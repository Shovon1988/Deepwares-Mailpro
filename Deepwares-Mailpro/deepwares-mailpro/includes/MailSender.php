<?php
namespace Deepwares\MailPro;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

defined('ABSPATH') || exit;

class MailSender {

    /**
     * Last PHPMailer / SMTP error message.
     * Settings "Test Connection" screen can read this to show a better error.
     *
     * @var string
     */
    public static $last_error = '';

    /**
     * Send an email using saved SMTP settings.
     *
     * @param string      $to       Recipient email.
     * @param string      $subject  Email subject.
     * @param string      $body     HTML body.
     * @param string|null $altBody  Plain-text fallback (optional).
     * @return bool  True on success, false on failure.
     */
    public static function send( $to, $subject, $body, $altBody = null ) {
        self::$last_error = '';

        // Ensure PHPMailer classes are loaded from WordPress core.
        if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }

        $smtp = get_option( 'dwmp_smtp', [] );

        $mail = new PHPMailer( true );

        try {
            // -----------------------------------------------------------------
            // SMTP configuration
            // -----------------------------------------------------------------
            $mail->isSMTP();
            $mail->Host     = isset( $smtp['host'] ) ? $smtp['host'] : '';
            $mail->Port     = isset( $smtp['port'] ) ? (int) $smtp['port'] : 587;
            $mail->SMTPAuth = true;

            $mail->Username = isset( $smtp['username'] ) ? $smtp['username'] : '';
            $mail->Password = isset( $smtp['password'] ) ? $smtp['password'] : '';

            // Pick encryption:
            //   - use saved value if present
            //   - otherwise infer from port (465 => SMTPS, 587 => STARTTLS)
            $enc = isset( $smtp['encryption'] ) ? trim( strtolower( $smtp['encryption'] ) ) : '';

            if ( ! $enc ) {
                if ( $mail->Port === 465 ) {
                    $enc = 'ssl';
                } elseif ( $mail->Port === 587 ) {
                    $enc = 'tls';
                }
            }

            if ( $enc ) {
                if ( in_array( $enc, [ 'ssl', 'smtps' ], true ) ) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ( in_array( $enc, [ 'tls', 'starttls' ], true ) ) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }
            }

            // Auto-upgrade to TLS when supported
            $mail->SMTPAutoTLS = true;

            // -----------------------------------------------------------------
            // Sender / recipient details
            // -----------------------------------------------------------------
            $fromEmail = ! empty( $smtp['from_email'] )
                ? $smtp['from_email']
                : get_option( 'admin_email' );

            $fromName  = ! empty( $smtp['from_name'] )
                ? $smtp['from_name']
                : get_bloginfo( 'name' );

            $replyTo   = ! empty( $smtp['reply_to'] )
                ? $smtp['reply_to']
                : $fromEmail;

            $mail->setFrom( $fromEmail, $fromName );
            $mail->addReplyTo( $replyTo );
            $mail->addAddress( $to );

            // -----------------------------------------------------------------
            // Content
            // -----------------------------------------------------------------
            $mail->isHTML( true );
            $mail->Subject = (string) $subject;
            $mail->Body    = (string) $body;
            $mail->AltBody = $altBody !== null ? (string) $altBody : wp_strip_all_tags( $body );

            // -----------------------------------------------------------------
            // Send
            // -----------------------------------------------------------------
            return $mail->send();

        } catch ( Exception $e ) {
            // Capture and log a detailed error so the Settings page can show it.
            self::$last_error = $mail->ErrorInfo ?: $e->getMessage();
            error_log( 'MailSender error sending to ' . $to . ': ' . self::$last_error );
            return false;
        }
    }
}
