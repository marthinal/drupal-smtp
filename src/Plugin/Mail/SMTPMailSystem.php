<?php
/**
 * @file
 * The code processing mail in the smtp module.
 *
 */

namespace Drupal\smtp\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;
use Drupal\smtp\PHPMailer\PHPMailer;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Component\Utility\Unicode;

/**
 * Modify the drupal mail system to use smtp when sending emails.
 * Include the option to choose between plain text or HTML
 *
 * @Mail(
 *   id = "SMTPMailSystem",
 *   label = @Translation("SMTP Mailer"),
 *   description = @Translation("Sends the message as plain text, using SMTP.")
 * )
 */
class SMTPMailSystem implements MailInterface {

  protected $AllowHtml;

  /**
   * Config settings for the smtp module.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $smtpConfig;

  /**
   * The PHPMailer object.
   *
   * @var \Drupal\smtp\PHPMailer\PHPMailer
   */
  protected $mailer;


  /**
   * Constructs a SMPTMailSystem object.
   */
  public function __construct() {
    $this->smtpConfig = \Drupal::config('smtp.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $this->AllowHtml = $this->smtpConfig->get('smtp_allowhtml');
    // Join the body array into one string.
    $message['body'] = implode("\n\n", $message['body']);
    if ($this->AllowHtml == 0) {
      // Convert any HTML to plain-text.
      $message['body'] = MailFormatHelper::htmlToText($message['body']);
      // Wrap the mail body for sending.
      $message['body'] = MailFormatHelper::wrapMail($message['body']);
    }
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    $this->mailer = new PHPMailer();
    $this->prepareMailer($message);

    return $this->sendMail($message);
  }

  /**
   * @param array $message
   * @return bool
   * @throws \Drupal\smtp\Plugin\Exception\PHPMailerException
   * @throws \Exception
   */
  protected function sendMail(array $message) {
    // Send the email.
    /*if ($this->smtpConfig->get('smtp_queue')) {
      watchdog('smtp', 'Queue sending mail to: @to', array('@to' => $to));
      smtp_send_queue($this->mailerArr);
    }
    else {*/
    // Let the people know what is going on.
    \Drupal::logger('smtp')->info('Sending mail to: @to', array('@to' => $message['to']));

    // Try to send e-mail. If it fails, set watchdog entry.
    if (!$this->mailer->Send()) {
      \Drupal::logger('smtp')->error('Error sending e-mail from @from to @to : !error_message', array('@from' => $message['from'], '@to' => $message['to'], '!error_message' => $this->mailer->ErrorInfo));
      return FALSE;
    }
    $this->mailer->SmtpClose();
    unset($this->mailer);
    return TRUE;
    // }
  }

  /**
   * Adds values to the mailer.
   *
   * @param array $message
   * @param PHPMailer $this->mailer
   * @return PHPMailer
   */
  protected function prepareMailer(array $message) {
    $this->setSmtpDebug();
    $this->setFromMailer($message);
    $this->setToRecipients($message);
    $this->processMailerHeadersSettings($message);
    $this->setSubject($message);
    // @TODO processMessageBody() should be removed because we have different methods doing
    // the job at processMailerHeadersSettings()
    //$this->processMessageBody($message);
    $this->setAttachments($message);
    $this->setAuthenticationSettings();
    $this->setProtocolPrefix();
    $this->setOtherConnectionSettings();
  }

  /**
   * @param array $message
   */
  protected function setToRecipients(array $message) {
    $torecipients = explode(',', $message['to']);
    foreach ($torecipients as $torecipient) {
      if (strpos($torecipient, '<') !== FALSE) {
        $toparts = explode(' <', $torecipient);
        $toname = $toparts[0];
        $toaddr = rtrim($toparts[1], '>');
      }
      else {
        $toname = '';
        $toaddr = $torecipient;
      }
      $this->mailer->AddAddress($toaddr, $toname);
    }
  }

  /**
   * @param array $message
   * @return bool
   */
  protected function setFromMailer(array $message) {

    // Set the from name.
    $from_name = $this->smtpConfig->get('smtp_fromname');
    if (empty($from_name)) {
      // If value is not defined in settings, use site_name.
      $from_name = $this->smtpConfig->get('site_name');
    }

    //Hack to fix reply-to issue.
    $properfrom = $this->smtpConfig->get('site_mail');
    if (!empty($properfrom)) {
      $message['headers']['From'] = $properfrom;
    }
    if (!isset($message['headers']['Reply-To']) || empty($message['headers']['Reply-To'])) {
      if (strpos($message['from'], '<')) {
        $reply = preg_replace('/>.*/', '', preg_replace('/.*</', '', $message['from']));
      }
      else {
        $reply = $message['from'];
      }
      $message['headers']['Reply-To'] = $reply;
    }

    // Blank value will let the e-mail address appear.

    if ($message['from'] == NULL || $message['from'] == '') {
      // If from e-mail address is blank, use smtp_from config option.
      if (($message['from'] = $this->smtpConfig->get('smtp_from')) == '') {
        // If smtp_from config option is blank, use site_email.
        if (($message['from'] = $this->smtpConfig->get('site_mail')) == '') {
          drupal_set_message(t('There is no submitted from address.'), 'error');
          \Drupal::logger('smtp')->error('There is no submitted from address.');
          return FALSE;
        }
      }
    }
    if (preg_match('/^"?.*"?\s*<.*>$/', $message['from'])) {
      // . == Matches any single character except line break characters \r and \n.
      // * == Repeats the previous item zero or more times.
      $from_name = preg_replace('/"?([^("\t\n)]*)"?.*$/', '$1', $message['from']); // It gives: Name
      $message['from'] = preg_replace("/(.*)\<(.*)\>/i", '$2', $message['from']); // It gives: name@domain.tld
    }
    elseif (!\Drupal::service('email.validator')->isValid($message['from'])) {
      drupal_set_message(t('The submitted from address (@from) is not valid.', array('@from' => $message['from'])), 'error');
      \Drupal::logger('smtp')->error('The submitted from address (@from) is not valid.');
      return FALSE;
    }

    // Defines the From value to what we expect.
    $this->mailer->From = $message['from'];
    $this->mailer->FromName = $from_name;
    $this->mailer->Sender = $message['from'];
  }

  /**
   *
   */
  protected function setSmtpDebug() {
    // Turn on debugging, if requested.
    if ($this->smtpConfig->get('smtp_debugging') == 1) {
      $this->mailer->SMTPDebug = TRUE;
    }
    else {
      $this->mailer->SMTPDebug = FALSE;
    }
  }

  /**
   * @param array $message
   */
  protected function processMailerHeadersSettings(array $message) {

    foreach ($message['headers'] as $key => $value) {
      switch (strtolower($key)) {
        case 'from':
          if ($message['from'] == NULL or $message['from'] == '') {
            // If a from value was already given, then set based on header.
            // Should be the most common situation since drupal_mail moves the
            // from to headers.
            $message['from'] = $value;
            $this->mailer->From = $value;
            // then from can be out of sync with from_name !
            $this->mailer->FromName = '';
            $this->mailer->Sender = $value;
          }
          break;
        case 'content-type':
          // Parse several values on the Content-type header, storing them in an array like
          // key=value -> $vars['key']='value'
          $vars = explode(';', $value);
          foreach ($vars as $i => $var) {
            if ($cut = strpos($var, '=')) {
              $new_var = trim(strtolower(substr($var, $cut + 1)));
              $new_key = trim(substr($var, 0, $cut));
              unset($vars[$i]);
              $vars[$new_key] = $new_var;
            }
          }
          // Set the charset based on the provided value, otherwise set it to UTF-8 (which is Drupals internal default).
          $this->mailer->CharSet = isset($vars['charset']) ? $vars['charset'] : 'UTF-8';
          // If $vars is empty then set an empty value at index 0 to avoid a PHP warning in the next statement
          $vars[0] = isset($vars[0])?$vars[0]:'';

          switch ($vars[0]) {
            case 'text/plain':
              // The message includes only a plain text part.
              $this->mailer->IsHTML(FALSE);
              $this->mailer->ContentType = 'text/plain';
              $this->processMessageDefaultBody($message);
              break;

            case 'text/html':
              // The message includes only an HTML part.
              $this->mailer->IsHTML(TRUE);
              $this->mailer->ContentType = 'text/html';
              $this->processMessageDefaultBody($message);
              break;

            case 'multipart/related':
              // Get the boundary ID from the Content-Type header.
              $boundary = $this->_get_substring($value, 'boundary', '"', '"');

              // The message includes an HTML part w/inline attachments.
              $this->mailer->ContentType = 'multipart/related; boundary="' . $boundary . '"';
              $this->processMessageDefaultBody($message);
              break;

            case 'multipart/alternative':
              // The message includes both a plain text and an HTML part.
              $this->mailer->ContentType = $this->mailer->ContentType = 'multipart/alternative';

              // Get the boundary ID from the Content-Type header.
              $boundary = $this->_get_substring($value, 'boundary', '"', '"');
              $this->processMessageBodyMultiPartAlternative($message, $boundary);
              break;

            case 'multipart/mixed':
              // The message includes one or more attachments.
              $this->mailer->ContentType = $this->mailer->ContentType = 'multipart/mixed';

              // Get the boundary ID from the Content-Type header.
              $boundary = $this->_get_substring($value, 'boundary', '"', '"');
              $this->processMessageBodyMultiPartMixed($message, $boundary);
              break;

            default:
              // Everything else is unsuppored by PHPMailer.
              drupal_set_message(t('The %header of your message is not supported by PHPMailer and will be sent as text/plain instead.', array('%header' => "Content-Type: $value")), 'error');
              \Drupal::logger('smtp')->error('The %header of your message is not supported by PHPMailer and will be sent as text/plain instead.', array('%header' => "Content-Type: $value"));
              // Force the Content-Type to be text/plain.
              $this->mailer->IsHTML(FALSE);
              $this->mailer->ContentType = 'text/plain';
              $this->processMessageDefaultBody($message);
          }
          break;

        case 'reply-to':
          // Only add a "reply-to" if it's not the same as "return-path".
          if ($value != $message['headers']['Return-Path']) {
            if (strpos($value, '<') !== FALSE) {
              $replyToParts = explode('<', $value);
              $replyToName = trim($replyToParts[0]);
              $replyToName = trim($replyToName, '"');
              $replyToAddr = rtrim($replyToParts[1], '>');
              $this->mailer->AddReplyTo($replyToAddr, $replyToName);
            }
            else {
              $this->mailer->AddReplyTo($value);
            }
          }
          break;

        case 'content-transfer-encoding':
          $this->mailer->Encoding = $value;
          break;

        case 'return-path':
          //$this->mailer->Sender = $value;
          break;

        case 'mime-version':
        case 'x-mailer':
          // Let PHPMailer specify these.
          break;

        case 'errors-to':
          $this->mailer->AddCustomHeader('Errors-To: ' . $value);
          break;

        case 'cc':
          $ccrecipients = explode(',', $value);
          foreach ($ccrecipients as $ccrecipient) {
            if (strpos($ccrecipient, '<') !== FALSE) {
              $ccparts = explode(' <', $ccrecipient);
              $ccname = $ccparts[0];
              $ccaddr = rtrim($ccparts[1], '>');
            }
            else {
              $ccname = '';
              $ccaddr = $ccrecipient;
            }
            $this->mailer->AddCC($ccaddr, $ccname);
          }
          break;

        case 'bcc':
          $bccrecipients = explode(',', $value);
          foreach ($bccrecipients as $bccrecipient) {
            if (strpos($bccrecipient, '<') !== FALSE) {
              $bccparts = explode(' <', $bccrecipient);
              $bccname = $bccparts[0];
              $bccaddr = rtrim($bccparts[1], '>');
            }
            else {
              $bccname = '';
              $bccaddr = $bccrecipient;
            }
            $this->mailer->AddBCC($bccaddr, $bccname);
          }
          break;

        default:
          // The header key is not special - add it as is.
          $this->mailer->AddCustomHeader($key . ': ' . $value);
      }
    }
  }

  /**
   * @param array $message
   */
  protected function setSubject(array $message) {
    $this->mailer->Subject = $message['subject'];
  }

  /**
   * @param array $message
   * @param PHPMailer $this->mailer
   * @return PHPMailer
   * @throws \Drupal\smtp\Plugin\Exception\PHPMailerException
   * @throws \Exception
   */
  protected function processMessageBody(array $message) {
    /**
     * TODO
     * Need to figure out the following.
     *
     * Add one last header item, but not if it has already been added.
     * $errors_to = FALSE;
     * foreach ($this->mailer->CustomHeader as $custom_header) {
     *   if ($custom_header[0] = '') {
     *     $errors_to = TRUE;
     *   }
     * }
     * if ($errors_to) {
     *   $this->mailer->AddCustomHeader('Errors-To: '. $from);
     * }
     */
    switch ($this->mailer->ContentType) {

      case 'multipart/related':
        $this->mailer->Body = $message['body'];
        // TODO: Figure out if there is anything more to handling this type.
        break;

      case 'multipart/alternative':
        // Split the body based on the boundary ID.
        $body_parts = $this->_boundary_split($message['body'], $this->boundary);
        foreach ($body_parts as $body_part) {
          // If plain/text within the body part, add it to $this->mailer->AltBody.
          if (strpos($body_part, 'text/plain')) {
            // Clean up the text.
            $body_part = trim($this->_remove_headers(trim($body_part)));
            // Include it as part of the mail object.
            $this->mailer->AltBody = $body_part;
          }
          // If plain/html within the body part, add it to $this->mailer->Body.
          elseif (strpos($body_part, 'text/html')) {
            // Clean up the text.
            $body_part = trim($this->_remove_headers(trim($body_part)));
            // Include it as part of the mail object.
            $this->mailer->Body = $body_part;
          }
        }
        break;

      case 'multipart/mixed':
        // Split the body based on the boundary ID.
        $body_parts = $this->_boundary_split($message['body'], $this->boundary);

        // Determine if there is an HTML part for when adding the plain text part.
        $text_plain = FALSE;
        $text_html  = FALSE;
        foreach ($body_parts as $body_part) {
          if (strpos($body_part, 'text/plain')) {
            $text_plain = TRUE;
          }
          if (strpos($body_part, 'text/html')) {
            $text_html = TRUE;
          }
        }

        foreach ($body_parts as $body_part) {
          // If test/plain within the body part, add it to either
          // $this->mailer->AltBody or $this->mailer->Body, depending on whether there is
          // also a text/html part ot not.
          if (strpos($body_part, 'multipart/alternative')) {
            // Get boundary ID from the Content-Type header.
            $boundary2 = $this->_get_substring($body_part, 'boundary', '"', '"');
            // Clean up the text.
            $body_part = trim($this->_remove_headers(trim($body_part)));
            // Split the body based on the boundary ID.
            $body_parts2 = $this->_boundary_split($body_part, $boundary2);

            foreach ($body_parts2 as $body_part2) {
              // If plain/text within the body part, add it to $this->mailer->AltBody.
              if (strpos($body_part2, 'text/plain')) {
                // Clean up the text.
                $body_part2 = trim($this->_remove_headers(trim($body_part2)));
                // Include it as part of the mail object.
                $this->mailer->AltBody = $body_part2;
                $this->mailer->ContentType = 'multipart/mixed';
              }
              // If plain/html within the body part, add it to $this->mailer->Body.
              elseif (strpos($body_part2, 'text/html')) {
                // Get the encoding.
                $body_part2_encoding = $this->_get_substring($body_part2, 'Content-Transfer-Encoding', ' ', "\n");
                // Clean up the text.
                $body_part2 = trim($this->_remove_headers(trim($body_part2)));
                // Check whether the encoding is base64, and if so, decode it.
                if (Unicode::strtolower($body_part2_encoding) == 'base64') {
                  // Include it as part of the mail object.
                  $this->mailer->Body = base64_decode($body_part2);
                  // Ensure the whole message is recoded in the base64 format.
                  $this->mailer->Encoding = 'base64';
                }
                else {
                  // Include it as part of the mail object.
                  $this->mailer->Body = $body_part2;
                }
                $this->mailer->ContentType = 'multipart/mixed';
              }
            }
          }
          // If text/plain within the body part, add it to $this->mailer->Body.
          elseif (strpos($body_part, 'text/plain')) {
            // Clean up the text.
            $body_part = trim($this->_remove_headers(trim($body_part)));

            if ($text_html) {
              $this->mailer->AltBody = $body_part;
              $this->mailer->IsHTML(TRUE);
              $this->mailer->ContentType = 'multipart/mixed';
            }
            else {
              $this->mailer->Body = $body_part;
              $this->mailer->IsHTML(FALSE);
              $this->mailer->ContentType = 'multipart/mixed';
            }
          }
          // If text/html within the body part, add it to $this->mailer->Body.
          elseif (strpos($body_part, 'text/html')) {
            // Clean up the text.
            $body_part = trim($this->_remove_headers(trim($body_part)));
            // Include it as part of the mail object.
            $this->mailer->Body = $body_part;
            $this->mailer->IsHTML(TRUE);
            $this->mailer->ContentType = 'multipart/mixed';
          }
          // Add the attachment.
          elseif (strpos($body_part, 'Content-Disposition: attachment;') && !isset($message['params']['attachments'])) {
            $file_path     = $this->_get_substring($body_part, 'filename=', '"', '"');
            $file_name     = $this->_get_substring($body_part, ' name=', '"', '"');
            $file_encoding = $this->_get_substring($body_part, 'Content-Transfer-Encoding', ' ', "\n");
            $file_type     = $this->_get_substring($body_part, 'Content-Type', ' ', ';');

            if (file_exists($file_path)) {
              if (!$this->mailer->AddAttachment($file_path, $file_name, $file_encoding, $file_type)) {
                drupal_set_message(t('Attahment could not be found or accessed.'));
              }
            }
            else {
              // Clean up the text.
              $body_part = trim($this->_remove_headers(trim($body_part)));

              if (Unicode::strtolower($file_encoding) == 'base64') {
                $attachment = base64_decode($body_part);
              }
              elseif (Unicode::strtolower($file_encoding) == 'quoted-printable') {
                $attachment = quoted_printable_decode($body_part);
              }
              else {
                $attachment = $body_part;
              }

              $attachment_new_filename = \Drupal::service('file_system')->tempnam('temporary://', 'smtp');
              $file_path = file_save_data($attachment, $attachment_new_filename, FILE_EXISTS_REPLACE);
              $real_path = \Drupal::service('file_system')->realpath($file_path->uri);

              if (!$this->mailer->AddAttachment($real_path, $file_name)) {
                drupal_set_message(t('Attachment could not be found or accessed.'));
              }
            }
          }
        }
        break;

      default:
        $this->mailer->Body = $message['body'];
        break;
    }
  }

  /**
   * @param array $message
   * @param $boundary
   */
  protected function processMessageBodyMultiPartAlternative(array $message, $boundary) {
    // Split the body based on the boundary ID.
    $body_parts = $this->_boundary_split($message['body'], $boundary);
    foreach ($body_parts as $body_part) {
      // If plain/text within the body part, add it to $this->mailer->AltBody.
      if (strpos($body_part, 'text/plain')) {
        // Clean up the text.
        $body_part = trim($this->_remove_headers(trim($body_part)));
        // Include it as part of the mail object.
        $this->mailer->AltBody = $body_part;
      }
      // If plain/html within the body part, add it to $this->mailer->Body.
      elseif (strpos($body_part, 'text/html')) {
        // Clean up the text.
        $body_part = trim($this->_remove_headers(trim($body_part)));
        // Include it as part of the mail object.
        $this->mailer->Body = $body_part;
      }
    }
  }

  /**
   * @param array $message
   * @param $boundary
   * @throws \Drupal\smtp\Plugin\Exception\PHPMailerException
   * @throws \Exception
   */
  protected function processMessageBodyMultiPartMixed(array $message, $boundary) {
    // Split the body based on the boundary ID.
    $body_parts = $this->_boundary_split($message['body'], $boundary);

    // Determine if there is an HTML part for when adding the plain text part.
    $text_plain = FALSE;
    $text_html  = FALSE;
    foreach ($body_parts as $body_part) {
      if (strpos($body_part, 'text/plain')) {
        $text_plain = TRUE;
      }
      if (strpos($body_part, 'text/html')) {
        $text_html = TRUE;
      }
    }

    foreach ($body_parts as $body_part) {
      // If test/plain within the body part, add it to either
      // $this->mailer->AltBody or $this->mailer->Body, depending on whether there is
      // also a text/html part ot not.
      if (strpos($body_part, 'multipart/alternative')) {
        // Get boundary ID from the Content-Type header.
        $boundary2 = $this->_get_substring($body_part, 'boundary', '"', '"');
        // Clean up the text.
        $body_part = trim($this->_remove_headers(trim($body_part)));
        // Split the body based on the boundary ID.
        $body_parts2 = $this->_boundary_split($body_part, $boundary2);

        foreach ($body_parts2 as $body_part2) {
          // If plain/text within the body part, add it to $this->mailer->AltBody.
          if (strpos($body_part2, 'text/plain')) {
            // Clean up the text.
            $body_part2 = trim($this->_remove_headers(trim($body_part2)));
            // Include it as part of the mail object.
            $this->mailer->AltBody = $body_part2;
            $this->mailer->ContentType = 'multipart/mixed';
          }
          // If plain/html within the body part, add it to $this->mailer->Body.
          elseif (strpos($body_part2, 'text/html')) {
            // Get the encoding.
            $body_part2_encoding = $this->_get_substring($body_part2, 'Content-Transfer-Encoding', ' ', "\n");
            // Clean up the text.
            $body_part2 = trim($this->_remove_headers(trim($body_part2)));
            // Check whether the encoding is base64, and if so, decode it.
            if (Unicode::strtolower($body_part2_encoding) == 'base64') {
              // Include it as part of the mail object.
              $this->mailer->Body = base64_decode($body_part2);
              // Ensure the whole message is recoded in the base64 format.
              $this->mailer->Encoding = 'base64';
            }
            else {
              // Include it as part of the mail object.
              $this->mailer->Body = $body_part2;
            }
            $this->mailer->ContentType = 'multipart/mixed';
          }
        }
      }
      // If text/plain within the body part, add it to $this->mailer->Body.
      elseif (strpos($body_part, 'text/plain')) {
        // Clean up the text.
        $body_part = trim($this->_remove_headers(trim($body_part)));

        if ($text_html) {
          $this->mailer->AltBody = $body_part;
          $this->mailer->IsHTML(TRUE);
          $this->mailer->ContentType = 'multipart/mixed';
        }
        else {
          $this->mailer->Body = $body_part;
          $this->mailer->IsHTML(FALSE);
          $this->mailer->ContentType = 'multipart/mixed';
        }
      }
      // If text/html within the body part, add it to $this->mailer->Body.
      elseif (strpos($body_part, 'text/html')) {
        // Clean up the text.
        $body_part = trim($this->_remove_headers(trim($body_part)));
        // Include it as part of the mail object.
        $this->mailer->Body = $body_part;
        $this->mailer->IsHTML(TRUE);
        $this->mailer->ContentType = 'multipart/mixed';
      }
      // Add the attachment.
      elseif (strpos($body_part, 'Content-Disposition: attachment;') && !isset($message['params']['attachments'])) {
        $file_path     = $this->_get_substring($body_part, 'filename=', '"', '"');
        $file_name     = $this->_get_substring($body_part, ' name=', '"', '"');
        $file_encoding = $this->_get_substring($body_part, 'Content-Transfer-Encoding', ' ', "\n");
        $file_type     = $this->_get_substring($body_part, 'Content-Type', ' ', ';');

        if (file_exists($file_path)) {
          if (!$this->mailer->AddAttachment($file_path, $file_name, $file_encoding, $file_type)) {
            drupal_set_message(t('Attahment could not be found or accessed.'));
          }
        }
        else {
          // Clean up the text.
          $body_part = trim($this->_remove_headers(trim($body_part)));

          if (Unicode::strtolower($file_encoding) == 'base64') {
            $attachment = base64_decode($body_part);
          }
          elseif (Unicode::strtolower($file_encoding) == 'quoted-printable') {
            $attachment = quoted_printable_decode($body_part);
          }
          else {
            $attachment = $body_part;
          }

          $attachment_new_filename = \Drupal::service('file_system')->tempnam('temporary://', 'smtp');
          $file_path = file_save_data($attachment, $attachment_new_filename, FILE_EXISTS_REPLACE);
          $real_path = \Drupal::service('file_system')->realpath($file_path->uri);

          if (!$this->mailer->AddAttachment($real_path, $file_name)) {
            drupal_set_message(t('Attachment could not be found or accessed.'));
          }
        }
      }
    }
  }

  /**
   * @param array $message
   */
  protected function processMessageDefaultBody(array $message) {
    $this->mailer->Body = $message['body'];
  }

  /**
   * @param array $message
   * @param PHPMailer $this->mailer
   * @return PHPMailer
   * @throws \Drupal\smtp\Plugin\Exception\PHPMailerException
   * @throws \Exception
   */
  protected function setAttachments(array $message) {
    if (isset($message['params']['attachments'])) {
      foreach ($message['params']['attachments'] as $attachment) {
        if (isset($attachment['filecontent'])) {
          $this->mailer->AddStringAttachment($attachment['filecontent'], $attachment['filename'], 'base64', $attachment['filemime']);
        }
        if (isset($attachment['filepath'])) {
          $filename = isset($attachment['filename']) ? $attachment['filename'] : basename($attachment['filepath']);
          $filemime = isset($attachment['filemime']) ? $attachment['filemime'] : file_get_mimetype($attachment['filepath']);
          $this->mailer->AddAttachment($attachment['filepath'], $filename, 'base64', $filemime);
        }
      }
    }
  }

  /**
   * @param PHPMailer $this->mailer
   */
  protected function setProtocolPrefix() {
    switch ($this->smtpConfig->get('smtp_protocol')) {
      case 'ssl':
        $this->mailer->SMTPSecure = 'ssl';
        break;

      case 'tls':
        $this->mailer->SMTPSecure = 'tls';
        break;

      default:
        $this->mailer->SMTPSecure = '';
    }
  }

  /**
   * Sets the authentication settings.
   */
  protected function setAuthenticationSettings() {
    $username = $this->smtpConfig->get('smtp_username');
    $password = $this->smtpConfig->get('smtp_password');

    // If username and password are given, use SMTP authentication.
    if ($username != '' && $password != '') {
      $this->mailer->SMTPAuth = TRUE;
      $this->mailer->Username = $username;
      $this->mailer->Password = $password;
    }
  }

  /**
   * Sets other connection settings.
   */
  protected function setOtherConnectionSettings() {
    $this->mailer->Host = $this->smtpConfig->get('smtp_host') . ';' . $this->smtpConfig->get('smtp_hostbackup');
    $this->mailer->Port = $this->smtpConfig->get('smtp_port');
    $this->mailer->Mailer = 'smtp';
  }

  /**
   * Splits the input into parts based on the given boundary.
   *
   * Swiped from Mail::MimeDecode, with modifications based on Drupal's coding
   * standards and this bug report: http://pear.php.net/bugs/bug.php?id=6495
   *
   * @param $input
   * @param $boundary
   * @return array
   */
  protected function _boundary_split($input, $boundary) {
    $parts = array();
    $bs_possible = substr($boundary, 2, -2);
    $bs_check    = '\"' . $bs_possible . '\"';

    if ($boundary == $bs_check) {
      $boundary = $bs_possible;
    }

    $tmp = explode('--' . $boundary, $input);

    for ($i = 1; $i < count($tmp); $i++) {
      if (trim($tmp[$i])) {
        $parts[] = $tmp[$i];
      }
    }

    return $parts;
  }

  /**
   * Strips the headers from the body part.
   *
   * @param $input
   *   A string containing the body part to strip.
   *
   * @return string
   *   A string with the stripped body part.
   */
  protected function _remove_headers($input) {
    $part_array = explode("\n", $input);

    // will strip these headers according to RFC2045
    $this->headers_to_strip = array( 'Content-Type', 'Content-Transfer-Encoding', 'Content-ID', 'Content-Disposition');
    $pattern = '/^(' . implode('|', $this->headers_to_strip) . '):/';

    while (count($part_array) > 0) {

      // ignore trailing spaces/newlines
      $line = rtrim($part_array[0]);

      // if the line starts with a known header string
      if (preg_match($pattern, $line)) {
        $line = rtrim(array_shift($part_array));
        // remove line containing matched header.

        // if line ends in a ';' and the next line starts with four spaces, it's a continuation
        // of the header split onto the next line. Continue removing lines while we have this condition.
        while (substr($line, -1) == ';' && count($part_array) > 0 && substr($part_array[0], 0, 4) == '    ') {
          $line = rtrim(array_shift($part_array));
        }
      }
      else {
        // no match header, must be past headers; stop searching.
        break;
      }
    }

    $output = implode("\n", $part_array);
    return $output;
  }

  /**
   * @param $source
   * @param $target
   * @param $beginning_character
   * @param $ending_character
   * @return string
   */
  protected function _get_substring($source, $target, $beginning_character, $ending_character) {
    $search_start     = strpos($source, $target) + 1;
    $first_character  = strpos($source, $beginning_character, $search_start) + 1;
    $second_character = strpos($source, $ending_character, $first_character) + 1;
    $substring        = substr($source, $first_character, $second_character - $first_character);
    $string_length    = strlen($substring) - 1;

    if ($substring[$string_length] == $ending_character) {
      $substring = substr($substring, 0, $string_length);
    }

    return $substring;
  }  //  End of _smtp_get_substring().
}
