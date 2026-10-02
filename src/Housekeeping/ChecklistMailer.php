<?php
declare(strict_types=1);

namespace One\Housekeeping;

/**
 * Checklist report e-mail with the 3 photos attached. Native mail() with a hand-built
 * multipart/mixed body — PHPMailer is not available on the server (legacy send_email.php fallback).
 */
final class ChecklistMailer
{
    /**
     * @param array<string, array{title:string, items:array<string,bool>}> $sections label => checked
     * @param list<array{path:string, filename:string, requirement:string, size:int}> $photos
     */
    public static function send(
        string $apartment,
        string $maid,
        string $date,
        int $submission,
        array $sections,
        array $photos,
        string $submittedBy
    ): bool {
        $cfg = config('housekeeping', []);
        $to = (string) ($cfg['report_to'] ?? 'cleaning@smartconceptliving.ro');
        $from = (string) ($cfg['from'] ?? 'no-reply@smartconceptliving.ro');
        $fromName = (string) ($cfg['from_name'] ?? 'SmartStay Cleaning System');

        $text = "Cleaning Report for Apartment $apartment\n"
            . "Maid: $maid\n"
            . "Date: $date\n"
            . "Submission: #$submission of " . Checklist::MAX_SUBMISSIONS . "\n";
        if ($submittedBy !== $maid) {
            $text .= "Trimis din SmartStay ONE de: $submittedBy\n";
        }
        $text .= "\nFOTOGRAFII ATASATE:\n";
        foreach ($photos as $i => $photo) {
            $text .= ($i + 1) . '. ' . $photo['requirement'] . ' (' . round($photo['size'] / 1024, 1) . "KB)\n";
        }
        $text .= "\n";
        foreach ($sections as $key => $section) {
            $text .= strtoupper($key) . ":\n";
            foreach ($section['items'] as $label => $checked) {
                $text .= ($checked ? "[\u{2713}] " : '[x] ') . $label . "\n";
            }
            $text .= "\n";
        }

        $boundary = 'ssmime_' . bin2hex(random_bytes(16));
        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n\r\n";
        foreach ($photos as $photo) {
            $data = @file_get_contents($photo['path']);
            if ($data === false) {
                error_log('[ONE] checklist attach unreadable: ' . $photo['path']);
                continue;
            }
            $name = str_replace(['"', "\r", "\n"], '', $photo['filename']);
            $body .= "--$boundary\r\n"
                . "Content-Type: image/jpeg; name=\"$name\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
                . chunk_split(base64_encode($data)) . "\r\n";
        }
        $body .= "--$boundary--";

        $subject = "Cleaning Checklist - Apartment $apartment - $date (#$submission)";
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            "From: $fromName <$from>",
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
        ]);
        $sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        if (!$sent) {
            error_log("[ONE] checklist mail() failed for apt $apartment $date");
        }
        return $sent;
    }
}
