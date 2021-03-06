<?php
/**
 * Buoy SMS to Email Bridge
 *
 * Class for interacting with email accounts and forwarding as TXTs.
 *
 * @package WordPress\Plugin\WP_Buoy_Plugin\SMS_Email_Bridge
 *
 * @copyright Copyright (c) 2016 by Meitar "maymay" Moscovitz
 *
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

/**
 * Class for the bridge's interaction with WordPress.
 */
class WP_Buoy_SMS_Email_Bridge {

    /**
     * The WordPress hook name ("tag").
     *
     * @var string
     */
    const hook = 'buoy_sms_email_bridge_run';

    /**
     * The back-off timing multiplier.
     *
     * @var int
     */
    const backoff_multiplier = 2;

    /**
     * The back-off time step in seconds.
     *
     * @var int
     */
    const backoff_time_step = 30;

    /**
     * The maximum number of seconds to backoff for.
     *
     * @var int
     */
    const backoff_max_seconds = 600; // 10 minutes

    /**
     * Connects to an IMAP server with the settings from a given post.
     *
     * @param WP_Post $wp_post
     *
     * @return Horde_Imap_Client_Socket
     */
    private static function connectImap ($wp_post) {
        $settings = WP_Buoy_Settings::get_instance();
        // Connect to IMAP server.
        $imap_args = array(
            'username' => $wp_post->sms_email_bridge_username,
            'password' => $wp_post->sms_email_bridge_password,
            'hostspec' => $wp_post->sms_email_bridge_server,
            'port' => $wp_post->sms_email_bridge_port,
            'secure' => $wp_post->sms_email_bridge_connection_security,
        );
        if ($settings->get('debug')) {
            $imap_args['debug'] = WP_CONTENT_DIR.'/debug.log';
        }
        try {
            $imap_client = new Horde_Imap_Client_Socket($imap_args);
        } catch (Horde_Imap_Client_Exception $e) {
            if ($settings->get('debug')) {
                error_log(__CLASS__ . ' failed to instantiate IMAP client.');
            }
        }

        return $imap_client;
    }

    /**
     * Registers bridge to WordPress API.
     */
    public static function register () {
        add_action(self::hook, array(__CLASS__, 'run'), 10, 2);

        add_management_page(
            esc_html__('Buoy SMS-Email Bridge', 'buoy'),
            esc_html__('Buoy Team sms/txts', 'buoy'),
            'manage_options',
            'buoy_sms_email_bridge_tool',
            array(__CLASS__, 'renderSmsEmailBridgeToolPage')
        );
    }

    public static function renderSmsEmailBridgeToolPage () {
        include dirname(__FILE__).'/../pages/tool-page-sms-email-bridge.php';
    }

    /**
     * Performs a runtime check of an email address for SMS messages.
     *
     * This method is called by the WP-Cron system to perform a check
     * of an given team's SMS/txt email account.
     *
     * @param int $post_id The ID of the post ("team") whose settings to use.
     */
    public static function run ($post_id) {
        $post = get_post($post_id);
        if (null === $post || empty($post->sms_email_bridge_enabled)) {
            return; // no post? bridge disabled? nothing to do!
        }

        $settings = WP_Buoy_Settings::get_instance();

        // Get a list of confirmed team members with phone numbers.
        $team = new WP_Buoy_Team($post);
        $recipients = array();
        foreach ($team->get_confirmed_members() as $member) {
            $m = new WP_Buoy_User($member);
            if ($m->get_phone_number()) {
                $recipients[] = $m;
            }
        }

        $imap_client = self::connectImap($post);

        // Search IMAP server for any new new messages
        // that are `From` any of the team member's numbers
        $imap_query = new Horde_Imap_Client_Search_Query();
        $queries = array();

        foreach ($recipients as $rcpt) {
            $q = new Horde_Imap_Client_Search_Query();
            $q->headerText('From', $rcpt->get_phone_number());
            // and that we haven't yet "read"
            $q1 = new Horde_Imap_Client_Search_Query();
            $q1->flag(Horde_Imap_Client::FLAG_SEEN, false);
            $q->andSearch($q1);

            $queries[] = $q;
        }

        $imap_query->orSearch($queries);

        try {
            $results = $imap_client->search('INBOX', $imap_query);
        } catch (Horde_Imap_Client_Exception $e) {
            if ($settings->get('debug')) {
                error_log($e->raw_msg);
            }
        }

        // Fetch the content of each message we found
        if (isset($results) && $results['count']) {
            $f = new Horde_Imap_Client_Fetch_Query();
            $f->fullText();
            try {
                $fetched = $imap_client->fetch('INBOX', $f, array(
                    'ids' => $results['match']
                ));
                foreach ($fetched as $data) {
                    // get the body's plain text content
                    $message = Horde_Mime_Part::parseMessage($data->getFullMsg());
                    $body_id = $message->findBody();
                    $part = $message->getPart($body_id);
                    $txt = $part->getContents();

                    // and get the sender's number
                    $h = Horde_Mime_Headers::parseHeaders($data->getFullMsg());
                    $from_phone = $h->getHeader('From')->getAddressList(true)->first()->mailbox;

                    // forward the body text to each member of the team,
                    self::forward($txt, $recipients, WP_Buoy_User::getByPhoneNumber($from_phone));
                }
                // since there was a new message to forward,
                // schedule another run with reset back-off counter.
                self::scheduleNext($post_id, 0);
            } catch (Horde_Imap_Client_Exception $e) {
                // TODO: Handle fetch error.
            }
        } else { // couldn't get any new messages
            self::scheduleNext($post_id, get_post_meta($post_id, 'sms_email_bridge_backoff_step', true));
        }
    }

    /**
     * Schedules the next run for the given team.
     *
     * This method uses an adaptive recheck algorithm similar to TCP's
     * adaptive retransmission timer.
     *
     * @uses self::getNextRunTime Implements the adaptive timing algorithm.
     *
     * @param int $post_id
     * @param int $backoff_step
     */
    public static function scheduleNext ($post_id, $backoff_step = 0) {
        $backoff_step = absint($backoff_step);
        $time = self::getNextRunTime($backoff_step);

        $settings = WP_Buoy_Settings::get_instance();
        if ($settings->get('debug')) {
            $msg = sprintf(
                'Scheduling %s run for post ID %s at %s (back-off step is %s)',
                __CLASS__,
                $post_id,
                date('r', $time),
                $backoff_step
            );
            error_log($msg);
        }

        wp_schedule_single_event($time, self::hook, array($post_id));
        $next_step = (0 === $backoff_step) ? 1 : $backoff_step * self::backoff_multiplier;
        update_post_meta($post_id, 'sms_email_bridge_backoff_step', $next_step);
    }

    /**
     * Unschedules the next run of the bridge for the given team post.
     *
     * @param int $post_id
     */
    public static function unscheduleNext ($post_id) {
        $settings = WP_Buoy_Settings::get_instance();
        if ($settings->get('debug')) {
            error_log('Unscheduling '.__CLASS__.' run for post ID '.$post_id);
        }
        delete_post_meta($post_id, 'sms_email_bridge_backoff_step');
        if ($next_time = wp_next_scheduled(self::hook, array($post_id))) {
            wp_unschedule_event($next_time, self::hook, array($post_id));
        }
    }

    /**
     * Determines when the next run should be.
     *
     * This is implemented by providing a "back-off timer" value as a
     * counter beginning from 0. When 0 is passed, the back-off value
     * is equal to the time step. Otherwise, the counter is multiplied
     * by a multiplier (usually 2).
     *
     * This creates the following situation when the time step is 30 seconds
     * and the multiplier value is 2:
     *
     *     Run number 1, back-off counter 0, next run in 30 seconds
     *     Run number 2, back-off counter 1, next run in 1 minute
     *     Run number 3, back-off counter 2, next run in 2 minutes
     *     Run number 4, back-off counter 4, next run in 4 minutes
     *     Run number 5, back-off counter 8, next run in 8 minutes
     *
     * Total elapsed time for five runs is 15 minutes and 30 seconds.
     * When message activity is detected, we reset the counter to 0.
     *
     * This algorithm helps ensure we don't overload the remote server
     * but still lets us detect the presence and then forward messages
     * relatively quickly when an active conversation is taking place.
     *
     * The algorithm above is similar to TCP's adaptive retransmission
     * algorithm. (Research that algorithm for more insight on this.)
     *
     * @param int $backoff_step
     *
     * @return int
     */
    private static function getNextRunTime ($backoff_step) {
        $backoff = (0 === $backoff_step)
            ? self::backoff_time_step
            : (self::backoff_time_step * ($backoff_step * self::backoff_multiplier));
        if ($backoff > self::backoff_max_seconds) {
            $backoff = self::backoff_max_seconds;
        }
        return time() + $backoff;
    }

    /**
     * Forwards a text message to a set of recipients.
     *
     * @param string $text
     * @param WP_Buoy_User[] $recipients
     * @param WP_Buoy_User $sender
     */
    private static function forward ($text, $recipients, $sender) {
        $SMS = new WP_Buoy_SMS();
        $SMS->setSender($sender);
        $SMS->setContent($text);
        foreach ($recipients as $rcpt) {
            // don't address to the sender
            if ($sender->get_phone_number() !== $rcpt->get_phone_number()) {
                $SMS->addAddressee($rcpt);
            }
        }
        $SMS->send();
    }

}
