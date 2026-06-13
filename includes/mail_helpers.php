<?php
/**
 * Mail Helpers - Mini ERP System
 * Provides a standardized way to send emails using PHPMailer.
 */

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an email using configured SMTP settings.
 *
 * @param string|array $to Email address(es) to send to. Array can be ['email' => 'name', ...] or simple array.
 * @param string $subject The email subject
 * @param string $body The email body (HTML or plain text)
 * @param bool $isHTML Whether the body is HTML format
 * @return bool True on success, false on failure
 */
function send_mail($to, $subject, $body, $isHTML = false) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = (SMTP_ENCRYPTION === 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        if (is_array($to)) {
            foreach ($to as $key => $value) {
                if (is_int($key)) {
                    $mail->addAddress($value);
                } else {
                    $mail->addAddress($key, $value); // ['email@ex.com' => 'Name']
                }
            }
        } else {
            $mail->addAddress($to);
        }

        // Content
        $mail->isHTML($isHTML);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        if (!$isHTML) {
            $mail->AltBody = strip_tags($body);
        }

        return $mail->send();
    } catch (Exception $e) {
        // Log error silently, or throw it depending on preference
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
