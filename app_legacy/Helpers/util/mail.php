<?php

use Aws\CommandPool;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Facades\Log;
use LegacyApp\Community\Enums\ArticleType;
use LegacyApp\Site\Enums\Permissions;
use Symfony\Component\Mime\Email;

function sendRAEmail(string $to, string $subject, string $body): bool
{
    return mail_utf8($to, $subject, stripslashes(nl2br($body)));
}

function mail_utf8(string $to, string $subject = '(No subject)', string $message = ''): bool
{
    if (empty($to)) {
        return false;
    }

    if (config('mail.default') === 'smtp') {
        return mail_smtp($to, $subject, $message);
    }

    if (config('mail.default') === 'ses') {
        return mail_ses($to, $subject, $message);
    }

    return mail_log($to, $subject, $message);
}

function mail_log(string $to, string $subject = '(No subject)', string $message = ''): bool
{
    Log::debug('Mail', ['to' => $to, 'subject' => $subject, 'message' => $message]);

    return true;
}

function mail_smtp(string $to, string $subject = '(No subject)', string $message = ''): bool
{
    /** @var Mailer $mailer */
    $mailer = app()->make(MailerContract::class);

    /** @var SesTransport $transport */
    $transport = $mailer->getSymfonyTransport();

    $email = (new Email())
        ->from(config('mail.from.name') . ' <' . config('mail.from.address') . '>')
        ->to($to)
        ->subject($subject)
        ->html($message);

    $transport->send($email);

    return true;
}

function mail_ses(string $to, string $subject = '(No subject)', string $message = ''): bool
{
    /** @var Mailer $mailer */
    $mailer = app()->make(MailerContract::class);

    /** @var SesTransport $transport */
    $transport = $mailer->getSymfonyTransport();

    $client = $transport->ses();

    $recipients = [
        $to,
    ];

    // Queue emails as SendEmail commands
    $i = 100;
    $commands = [];
    foreach ($recipients as $recipient) {
        $commands[] = $client->getCommand('SendEmail', [
            // Pass the message id so it can be updated after it is processed (it's ignored by SES)
            'x-message-id' => $i,
            'Source' => config('mail.from.name') . ' <' . config('mail.from.address') . '>',
            'Destination' => [
                'ToAddresses' => [$recipient],
            ],
            'Message' => [
                'Subject' => [
                    'Data' => $subject,
                    'Charset' => 'UTF-8',
                ],
                'Body' => [
                    'Html' => [
                        'Data' => $message,
                        'Charset' => 'UTF-8',
                    ],
                ],
            ],
        ]);
        $i++;
    }

    try {
        $pool = new CommandPool($client, $commands, [
            'concurrency' => 10,
            // 'before' => function (CommandInterface $cmd, $iteratorId) {
            //     echo sprintf('About to send %d: %s'.PHP_EOL, $iteratorId, $a['Destination']['ToAddresses'][0]);
            //     error_log('About to send '.$iteratorId.': '.$a['Destination']['ToAddresses'][0]);
            //     $a = $cmd->toArray();
            // },
            // 'fulfilled' => function (ResultInterface $result, $iteratorId) use ($commands) {
            //     echo sprintf(
            //         'Completed %d: %s'.PHP_EOL,
            //         $commands[$iteratorId]['x-message-id'],
            //         $commands[$iteratorId]['Destination']['ToAddresses'][0]
            //     );
            //     error_log('Completed '.$commands[$iteratorId]['x-message-id'].' :'.$commands[$iteratorId]['Destination']['ToAddresses'][0]);
            // },
            // 'rejected' => function (AwsException $reason, $iteratorId) use ($commands) {
            //     echo sprintf(
            //         'Failed %d: %s'.PHP_EOL,
            //         $commands[$iteratorId]['x-message-id'],
            //         $commands[$iteratorId]['Destination']['ToAddresses'][0]
            //     );
            //
            //     error_log('Reason : '.$reason);
            //     error_log('Amazon SES Failed Rejected:'.$commands[$iteratorId]['x-message-id'].' :'.$commands[$iteratorId]['Destination']['ToAddresses'][0]);
            // },
        ]);
        // Initiate the pool transfers
        $promise = $pool->promise();
        // Force the pool to complete synchronously
        $promise->wait();

        return true;
    } catch (Exception $e) {
        Log::error($e->getMessage());

        return false;
    }
}

function sendValidationEmail(string $user, string $email): bool
{
    // This generates and stores (and returns) a new email validation string in the DB.
    $strValidation = generateEmailVerificationToken($user);
    $strEmailLink = config('app.url') . "/validateEmail.php?v=$strValidation";

    // $subject = "RetroAchievements.org - Confirm Email: $user";
    $subject = "Welcome to RetroAchievements.org, $user";

    $msg = "You or someone using your email address has attempted to sign up for an account at <a href='" . config('app.url') . "'>RetroAchievements.org</a><br>" .
        "<br>" .
        "If this was you, please click the following link to confirm this email address and complete sign up:<br>" .
        "<br>" .
        "&nbsp; &nbsp; &nbsp; &nbsp; <a href='$strEmailLink'>$strEmailLink</a><br>" .
        "<br>" .
        "If this wasn't you, please ignore this email.<br>" .
        "<br>" .
        "Thanks! And hope to see you on the forums!<br>" .
        "<br>" .
        "-- Your friends at <a href='" . config('app.url') . "'>RetroAchievements.org</a><br>";

    return mail_utf8($email, $subject, $msg);
}

function sendFriendEmail(string $user, string $email, int $type, string $friend): bool
{
    if ($user === $friend) {
        return false;
    }

    if ($type == 0) { // Requesting to be your friend
        $emailTitle = "$friend is now following you";
        $emailReason = "started following you";
        $link = "<a href='" . config('app.url') . "/user/$friend'>here</a>";
    } elseif ($type == 1) { // Friend request confirmed
        $emailTitle = "$friend is now following you";
        $emailReason = "followed you back";
        $link = "<a href='" . config('app.url') . "/user/$friend'>here</a>";
    } else {
        return false; // must break early! No nonsense emails please!
    }

    $msg = "Hello $user!<br>" .
        "$friend on RetroAchievements has $emailReason!<br>" .
        "Click $link to visit their user page!<br>" .
        "<br>" .
        "Thanks! And hope to see you on the forums!<br>" .
        "<br>" .
        "-- Your friends at RetroAchievements.org<br>";

    return mail_utf8($email, $emailTitle, $msg);
}

function informAllSubscribersAboutActivity(
    int $articleType,
    int $articleID,
    string $activityAuthor,
    ?string $onBehalfOfUser = null
): void {
    $subscribers = [];
    $subjectAuthor = null;
    $altURLTarget = null;
    $articleTitle = '';

    switch ($articleType) {
        case ArticleType::Game:
            $gameData = getGameData($articleID);
            $subscribers = getSubscribersOfGameWall($articleID);
            $articleTitle = $gameData['Title'] . ' (' . $gameData['ConsoleName'] . ')';
            break;

        case ArticleType::Achievement:
            $achievementData = GetAchievementData($articleID);
            $subscribers = getSubscribersOfAchievement($articleID, $achievementData['GameID'], $achievementData['Author']);
            $subjectAuthor = $achievementData['Author'];
            $articleTitle = $achievementData['Title'] . ' (' . $achievementData['GameTitle'] . ')';
            break;

        case ArticleType::User:  // User wall
            $wallUserData = getUserMetadataFromID($articleID);
            $subscribers = getSubscribersOfUserWall($articleID, $wallUserData['User']);
            $subjectAuthor = $wallUserData['User'];
            $altURLTarget = $wallUserData['User'];
            $articleTitle = $wallUserData['User'];
            break;

        case ArticleType::News:  // News
            break;

        case ArticleType::Activity:  // Activity (feed)
            $activityData = getActivityMetadata($articleID);
            $subscribers = getSubscribersOfFeedActivity($articleID, $activityData['User']);
            $subjectAuthor = $activityData['User'];
            $articleTitle = $activityData['User'];
            break;

        case ArticleType::Leaderboard:  // Leaderboard
            break;

        case ArticleType::AchievementTicket:  // Ticket
            $ticketData = getTicket($articleID);
            $subscribers = getSubscribersOfTicket($articleID, $ticketData['ReportedBy'], $ticketData['GameID']);
            $subjectAuthor = $ticketData['ReportedBy'];
            $articleTitle = $ticketData['AchievementTitle'] . ' (' . $ticketData['GameTitle'] . ')';
            break;

        default:
            break;
    }

    // some comments are generated by the user "Server" on behalf of other users whom we don't want to notify
    if ($onBehalfOfUser !== null) {
        $activityAuthor = $onBehalfOfUser;
    }

    foreach ($subscribers as $subscriber) {
        $isThirdParty = ($subscriber['User'] != $activityAuthor && ($subjectAuthor === null || $subscriber['User'] != $subjectAuthor));

        sendActivityEmail($subscriber['User'], $subscriber['EmailAddress'], $articleID, $activityAuthor, $articleType, $articleTitle, $isThirdParty, $altURLTarget);
    }
}

function sendActivityEmail(
    string $user,
    string $email,
    int $actID,
    string $activityCommenter,
    int $articleType,
    string $articleTitle,
    bool $threadInvolved = false,
    ?string $altURLTarget = null
): bool {
    if ($user === $activityCommenter || getUserPermissions($user) < Permissions::Unregistered) {
        return false;
    }

    switch ($articleType) {
        case ArticleType::Game:
            $emailTitle = "New Game Wall Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/game/$actID'>here</a>";
            $activityDescription = "the game wall for $articleTitle";
            break;

        case ArticleType::Achievement:
            $emailTitle = "New Achievement Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/achievement/$actID'>here</a>";
            $activityDescription = "the achievement wall for $articleTitle";
            break;

        case ArticleType::User:
            $emailTitle = "New User Wall Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/user/$altURLTarget'>here</a>";
            $activityDescription = "your user wall";
            if ($articleTitle !== $user) {
                $activityDescription = "$articleTitle's user wall";
            }
            break;

        case ArticleType::Leaderboard:
            $emailTitle = "New Leaderboard Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/leaderboardinfo.php?i=$actID'>here</a>";
            $activityDescription = "the leaderboard wall for $articleTitle";
            break;

        case ArticleType::Forum:
            $emailTitle = "New Forum Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/$altURLTarget'>here</a>";
            $activityDescription = "the forum post \"$articleTitle\"";
            break;

        case ArticleType::AchievementTicket:
            $emailTitle = "New Ticket Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/ticketmanager.php?i=$actID'>here</a>";
            $activityDescription = "the ticket you reported for $articleTitle";
            if ($threadInvolved) {
                $activityDescription = "a ticket for $articleTitle";
            }
            break;

        default:
            // generic messages
            $emailTitle = "New Activity Comment from $activityCommenter";
            $link = "<a href='" . config('app.url') . "/feed.php?a=$actID'>here</a>";
            $activityDescription = "Your latest activity";
            if ($threadInvolved) {
                $activityDescription = "A thread you've commented in";
            }
            break;
    }

    $msg = "Hello $user!<br>" .
        "$activityCommenter has commented on $activityDescription. " .
        "Click $link to see what they have written!<br>" .
        "<br>" .
        "Thanks! And hope to see you on the forums!<br>" .
        "<br>" .
        "-- Your friends at RetroAchievements.org<br>";

    return mail_utf8($email, $emailTitle, $msg);
}

function SendPrivateMessageEmail(
    string $user,
    string $email,
    string $title,
    string $contentIn,
    string $fromUser
): bool {
    if ($user === $fromUser) {
        return false;
    }

    $content = stripslashes(nl2br($contentIn));

    // Also used for Generic text:
    $emailTitle = "New Private Message from $fromUser";
    $link = "<a href='" . config('app.url') . "/inbox.php'>here</a>";

    $msg = "Hello $user!<br>" .
        "You have received a new private message from $fromUser.<br><br>" .
        "Title: $title<br>" .
        "$content<br><br>" .
        "Click $link to reply!<br>" .
        "Thanks! And hope to see you on the forums!<br>" .
        "<br>" .
        "-- Your friends at RetroAchievements.org<br>";

    return mail_utf8($email, $emailTitle, $msg);
}

function SendPasswordResetEmail(string $user, string $email, string $token): bool
{
    $emailTitle = "Password Reset Request";
    $link = "<a href='" . config('app.url') . "/resetPassword.php?u=$user&amp;t=$token'>Reset your password</a>";

    $msg = "Hello $user!<br>" .
        "Your account has requested a password reset:<br>" .
        "$link<br>" .
        "Thanks!<br>" .
        "-- Your friends at RetroAchievements.org<br>";

    return mail_utf8($email, $emailTitle, $msg);
}

function SendDeleteRequestEmail(string $user, string $email, string $deleteRequested): bool
{
    $emailTitle = "Account Deletion Request";

    $msg = "Hello $user,<br>" .
        "Your account has been marked for deletion.<br>" .
        "If you do not cancel this request before " . getDeleteDate($deleteRequested) . ", " .
        "you will no longer be able to access your account.<br>" .
        "Thanks!<br>" .
        "-- Your friends at RetroAchievements.org<br>";

    return mail_utf8($email, $emailTitle, $msg);
}

/**
 * Sends an email to all set requestors indicating new achievement have been
 * added when a set claim has been marked as complete.
 */
function sendSetRequestEmail(string $user, string $email, int $gameID, string $gameTitle): bool
{
    $emailTitle = "New Achievements Released for " . $gameTitle;
    $link = "<a href='" . config('app.url') . "/game/$gameID'>$gameTitle</a>";

    $msg = "Hello $user,<br>" .
        "A set that you have requested has received new achievements. Check out the new achievements added to $link.<br><br>" .
        "Thanks!<br>" .
        "-- Your friends at RetroAchievements.org<br>";

    return mail_utf8($email, $emailTitle, $msg);
}
