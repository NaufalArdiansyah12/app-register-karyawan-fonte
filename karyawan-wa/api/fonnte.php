<?php
/**
 * ============================================================================
 *  API WHATSAPP GATEWAY — FONNTE
 *  Menyediakan fungsi kirimWA($target, $pesan) melalui PHP cURL ke endpoint
 *  https://api.fonnte.com/send. Setiap status pengiriman dicatat ke file
 *  logs/wa_log.txt untuk memudahkan debugging.
 * ============================================================================
 */

require_once __DIR__ . '/../config/config.php';

if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit('Akses langsung ke file API tidak diizinkan.');
}

if (!function_exists('waLog')) {
    function waLog(string $status, string $target, string $respon): void {
        $folderLog = __DIR__ . '/../logs';
        if (!is_dir($folderLog)) { @mkdir($folderLog, 0755, true); }
        $baris = sprintf(
            "[%s] STATUS: %s | TARGET: %s | RESPON: %s%s",
            date('Y-m-d H:i:s'),
            $status,
            $target,
            $respon,
            PHP_EOL
        );
        @file_put_contents($folderLog . '/wa_log.txt', $baris, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('kirimWA')) {
    function kirimWA(string $target, string $pesan): bool {
        $target = preg_replace('/[^0-9]/', '', $target);
        $payload = ['target' => $target, 'message' => $pesan];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.fonnte.com/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Authorization: ' . FONNTE_TOKEN],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $respon = curl_exec($ch);
        $kodeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respon === false) { waLog('GAGAL-CURL', $target, (string) $error); return false; }
        if ($kodeHttp !== 200) { waLog('GAGAL-HTTP-' . $kodeHttp, $target, (string) $respon); return false; }

        $json = json_decode((string) $respon, true);
        $berhasil = is_array($json) && ($json['status'] ?? false) === true;
        waLog($berhasil ? 'TERKIRIM' : 'GAGAL-FONNTE', $target, (string) $respon);
        return $berhasil;
    }
}
