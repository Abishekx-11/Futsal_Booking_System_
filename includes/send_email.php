<?php
/**
 * send_email.php
 * -----------------------------------------------------------------
 * Sends email by talking raw SMTP over a socket to Gmail - no
 * PHPMailer, no mail library.  we
 * open a TCP connection with fsockopen(), speak the SMTP command
 * sequence by hand (EHLO, STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO,
 * DATA, QUIT), and read back the numeric response code after every
 * command to make sure the server actually accepted it.
 *
 * Uses the settings defined in includes/mail_config.php:
 *   SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD,
 *   SMTP_FROM_NAME, ADMIN_NOTIFICATION_EMAIL
 *
 * A failed email must NEVER break the booking/payment flow, so every
 * possible failure point is caught and logged with error_log()
 * instead of being allowed to throw or die().
 *
 * DIAGNOSTICS: every failure is logged together with the SERVER'S
 * OWN response text (not just our guess at why) - e.g. Gmail will
 * reply with something like "535-5.7.8 Username and Password not
 * accepted" when a login is rejected. That's what actually tells you
 * why, so always read the full logged line, not just the first part.
 * -----------------------------------------------------------------
 */

/**
 * Reads one full SMTP response from the socket. A response can span
 * multiple lines (e.g. "250-PIPELINING" ... "250 OK") - it isn't
 * finished until a line has a SPACE (not a dash) right after the
 * 3-digit code. Returns the response text, or false on a read error.
 */
function smtp_read_response($socket) {
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            return false;
        }
        $data .= $line;
        // Line looks like "250-..." (more lines follow) or "250 ..." (last line).
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    return $data;
}

/**
 * Sends one SMTP command (or raw data) and confirms the response
 * starts with one of the expected numeric codes. Returns true/false.
 * The full response text (Gmail's actual words, not just our
 * interpretation) is written into $rawResponse by reference, so the
 * caller can log exactly what the server said when something fails.
 */
function smtp_command($socket, $command, array $expectedCodes, &$rawResponse = null) {
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }
    $response = smtp_read_response($socket);
    $rawResponse = ($response === false) ? '(no response / connection closed)' : trim($response);
    if ($response === false) {
        return false;
    }
    $code = (int) substr($response, 0, 3);
    return in_array($code, $expectedCodes, true);
}

/**
 * Sends a plain-text email. Returns true on success, false on any
 * failure (and logs the reason via error_log so it can be debugged
 * without ever interrupting the page that called it).
 *
 * @param string $toEmail  Recipient's email address
 * @param string $toName   Recipient's display name
 * @param string $subject  Email subject line
 * @param string $bodyText Plain-text email body
 */
function sendEmail($toEmail, $toName, $subject, $bodyText) {
    if (!defined('SMTP_HOST') || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD')) {
        error_log('sendEmail: mail_config.php constants are not defined.');
        return false;
    }

    // trim() guards against an invisible trailing space/newline sneaking
    // into the config file from a copy-paste - that alone is enough to
    // make a correct-looking App Password fail authentication.
    $smtpUsername = trim(SMTP_USERNAME);
    $smtpPassword = trim(SMTP_PASSWORD);

    $socket = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
    if (!$socket) {
        error_log("sendEmail: could not connect to SMTP server - $errstr ($errno)");
        return false;
    }

    $raw = '';

    try {
        // Server greeting.
        if (!smtp_command($socket, null, [220], $raw)) {
            throw new Exception('No 220 greeting from SMTP server. Server said: ' . $raw);
        }

        // Say hello.
        if (!smtp_command($socket, 'EHLO localhost', [250], $raw)) {
            throw new Exception('EHLO was not accepted. Server said: ' . $raw);
        }

        // Upgrade the plain connection to TLS before sending any credentials.
        if (!smtp_command($socket, 'STARTTLS', [220], $raw)) {
            throw new Exception('STARTTLS was not accepted. Server said: ' . $raw);
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('TLS handshake failed.');
        }

        // Gmail requires EHLO to be sent again after the TLS upgrade.
        if (!smtp_command($socket, 'EHLO localhost', [250], $raw)) {
            throw new Exception('EHLO after STARTTLS was not accepted. Server said: ' . $raw);
        }

        // Authenticate: AUTH LOGIN, then base64 username, then base64 password.
        if (!smtp_command($socket, 'AUTH LOGIN', [334], $raw)) {
            throw new Exception('AUTH LOGIN was not accepted. Server said: ' . $raw);
        }
        if (!smtp_command($socket, base64_encode($smtpUsername), [334], $raw)) {
            throw new Exception('SMTP username was not accepted. Server said: ' . $raw);
        }
        if (!smtp_command($socket, base64_encode($smtpPassword), [235], $raw)) {
            throw new Exception('SMTP login failed. Server said: ' . $raw);
        }

        // Envelope.
        if (!smtp_command($socket, 'MAIL FROM:<' . $smtpUsername . '>', [250], $raw)) {
            throw new Exception('MAIL FROM was not accepted. Server said: ' . $raw);
        }
        if (!smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], $raw)) {
            throw new Exception('RCPT TO was not accepted. Server said: ' . $raw);
        }

        // Message body.
        if (!smtp_command($socket, 'DATA', [354], $raw)) {
            throw new Exception('DATA was not accepted. Server said: ' . $raw);
        }

        $fromHeader = SMTP_FROM_NAME . ' <' . $smtpUsername . '>';
        $toHeader   = $toName . ' <' . $toEmail . '>';

        // Dot-stuff any line in the body that starts with a lone "." so it
        // isn't mistaken for the end-of-message marker.
        $safeBody = preg_replace('/^\./m', '..', $bodyText);

        $message  = "From: {$fromHeader}\r\n";
        $message .= "To: {$toHeader}\r\n";
        $message .= "Subject: {$subject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "\r\n";
        $message .= $safeBody . "\r\n";
        $message .= ".";

        if (!smtp_command($socket, $message, [250], $raw)) {
            throw new Exception('Message body was not accepted. Server said: ' . $raw);
        }

        smtp_command($socket, 'QUIT', [221], $raw);
        fclose($socket);
        return true;

    } catch (Exception $e) {
        error_log('sendEmail failed: ' . $e->getMessage());
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}
