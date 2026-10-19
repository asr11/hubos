<?php

declare(strict_types=1);

namespace HubOS\Modules\Assets;

use DateTimeImmutable;
use DateTimeZone;
use HubOS\Modules\Import\ClientResolver;
use Throwable;

/**
 * Native PHP DNS + SSL + WHOIS inspector (zero third-party dependencies).
 * Supports HUBOS_MOCK_INSPECTOR=1 (or JSON string) for fast, deterministic offline E2E tests.
 */
final class NativeAssetInspector implements AssetInspector
{
    public function __construct(private int $timeoutSeconds = 4)
    {
    }

    public function inspect(string $domain): array
    {
        $host = ClientResolver::normDomain($domain) ?? strtolower(trim($domain));
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        $host = rtrim($host, '.');
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $mock = getenv('HUBOS_MOCK_INSPECTOR');
        if (is_string($mock) && $mock !== '' && $mock !== '0') {
            $decoded = json_decode($mock, true);
            if (is_array($decoded)) {
                return [
                    'host'              => $host,
                    'dns_a'             => array_values(array_map('strval', $decoded['dns_a'] ?? ['185.199.108.153'])),
                    'dns_ns'            => array_values(array_map('strval', $decoded['dns_ns'] ?? ['ns1.cloudflare.com', 'ns2.cloudflare.com'])),
                    'dns_mx'            => array_values(array_map('strval', $decoded['dns_mx'] ?? ['mx.' . $host])),
                    'ssl_status'        => (string) ($decoded['ssl_status'] ?? 'valid'),
                    'ssl_expiry_date'   => isset($decoded['ssl_expiry_date']) ? (string) $decoded['ssl_expiry_date'] : '2027-06-15',
                    'ssl_issuer'        => isset($decoded['ssl_issuer']) ? (string) $decoded['ssl_issuer'] : "Let's Encrypt",
                    'ssl_subject'       => $host,
                    'whois_expiry_date' => isset($decoded['whois_expiry_date']) ? (string) $decoded['whois_expiry_date'] : '2027-12-31',
                    'whois_registrar'   => isset($decoded['whois_registrar']) ? (string) $decoded['whois_registrar'] : 'Box / ISOC-IL',
                    'inspected_at'      => $now,
                ];
            }
            return [
                'host'              => $host,
                'dns_a'             => ['185.199.108.153'],
                'dns_ns'            => ['ns1.cloudflare.com', 'ns2.cloudflare.com'],
                'dns_mx'            => ['mx.' . $host],
                'ssl_status'        => 'valid',
                'ssl_expiry_date'   => '2027-06-15',
                'ssl_issuer'        => "Let's Encrypt",
                'ssl_subject'       => $host,
                'whois_expiry_date' => '2027-12-31',
                'whois_registrar'   => 'Box / ISOC-IL',
                'inspected_at'      => $now,
            ];
        }

        [$dnsA, $dnsNs, $dnsMx] = $this->inspectDns($host);
        [$sslStatus, $sslExpiry, $sslIssuer, $sslSubject] = $this->inspectSsl($host);
        [$whoisExpiry, $whoisRegistrar] = $this->inspectWhois($host);

        return [
            'host'              => $host,
            'dns_a'             => $dnsA,
            'dns_ns'            => $dnsNs,
            'dns_mx'            => $dnsMx,
            'ssl_status'        => $sslStatus,
            'ssl_expiry_date'   => $sslExpiry,
            'ssl_issuer'        => $sslIssuer,
            'ssl_subject'       => $sslSubject,
            'whois_expiry_date' => $whoisExpiry,
            'whois_registrar'   => $whoisRegistrar,
            'inspected_at'      => $now,
        ];
    }

    /**
     * @return array{0:list<string>, 1:list<string>, 2:list<string>}
     */
    private function inspectDns(string $host): array
    {
        if ($host === '' || !function_exists('dns_get_record')) {
            return [[], [], []];
        }
        $a = [];
        $ns = [];
        $mx = [];
        try {
            $records = @dns_get_record($host, DNS_A | DNS_NS | DNS_MX);
            if (is_array($records)) {
                foreach ($records as $r) {
                    $type = strtoupper((string) ($r['type'] ?? ''));
                    if ($type === 'A' && !empty($r['ip'])) {
                        $a[] = (string) $r['ip'];
                    } elseif ($type === 'NS' && !empty($r['target'])) {
                        $ns[] = strtolower((string) $r['target']);
                    } elseif ($type === 'MX' && !empty($r['target'])) {
                        $mx[] = strtolower((string) $r['target']);
                    }
                }
            }
        } catch (Throwable) {
        }
        if ($a === [] && function_exists('gethostbyname')) {
            $ip = @gethostbyname($host);
            if (is_string($ip) && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                $a[] = $ip;
            }
        }
        return [array_values(array_unique($a)), array_values(array_unique($ns)), array_values(array_unique($mx))];
    }

    /**
     * @return array{0:string, 1:?string, 2:?string, 3:?string}
     */
    private function inspectSsl(string $host): array
    {
        if ($host === '' || !function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) {
            return ['unknown', null, null, null];
        }
        try {
            $ctx = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'SNI_enabled'       => true,
                    'peer_name'         => $host,
                ],
            ]);
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client(
                'ssl://' . $host . ':443',
                $errno,
                $errstr,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
            if (!is_resource($sock)) {
                return ['none', null, null, null];
            }
            $params = stream_context_get_params($sock);
            fclose($sock);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return ['none', null, null, null];
            }
            $parsed = @openssl_x509_parse($cert);
            if (!is_array($parsed)) {
                return ['unknown', null, null, null];
            }
            $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
            $expiryDate = $validTo !== null ? gmdate('Y-m-d', $validTo) : null;
            $issuer = isset($parsed['issuer']['O'])
                ? (string) (is_array($parsed['issuer']['O']) ? $parsed['issuer']['O'][0] : $parsed['issuer']['O'])
                : (isset($parsed['issuer']['CN']) ? (string) $parsed['issuer']['CN'] : null);
            $subject = isset($parsed['subject']['CN'])
                ? (string) (is_array($parsed['subject']['CN']) ? $parsed['subject']['CN'][0] : $parsed['subject']['CN'])
                : null;

            $status = 'valid';
            if ($validTo !== null) {
                $daysLeft = intdiv($validTo - time(), 86400);
                if ($daysLeft < 0) {
                    $status = 'expired';
                } elseif ($daysLeft <= 14) {
                    $status = 'expiring';
                }
            }
            return [$status, $expiryDate, $issuer, $subject];
        } catch (Throwable) {
            return ['unknown', null, null, null];
        }
    }

    /**
     * @return array{0:?string, 1:?string}
     */
    private function inspectWhois(string $host): array
    {
        if ($host === '' || !function_exists('fsockopen')) {
            return [null, null];
        }
        $server = match (true) {
            str_ends_with($host, '.il')                        => 'whois.isoc.org.il',
            str_ends_with($host, '.com'), str_ends_with($host, '.net') => 'whois.verisign-grs.com',
            str_ends_with($host, '.org')                       => 'whois.pir.org',
            default                                            => null,
        };
        if ($server === null) {
            return [null, null];
        }
        try {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($server, 43, $errno, $errstr, $this->timeoutSeconds);
            if (!is_resource($fp)) {
                return [null, null];
            }
            stream_set_timeout($fp, $this->timeoutSeconds);
            fwrite($fp, $host . "\r\n");
            $raw = '';
            while (!feof($fp) && strlen($raw) < 32768) {
                $chunk = fread($fp, 2048);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
            fclose($fp);

            $expiry = null;
            if (preg_match('/(?:Registry Expiry Date|Expiration Date|validity|paid-till|expire)\s*:\s*([^\r\n]+)/i', $raw, $m) === 1) {
                $val = trim($m[1]);
                if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $val, $dm) === 1) {
                    $expiry = "{$dm[3]}-{$dm[2]}-{$dm[1]}";
                } else {
                    try {
                        $expiry = (new DateTimeImmutable($val, new DateTimeZone('UTC')))->format('Y-m-d');
                    } catch (Throwable) {
                        $expiry = null;
                    }
                }
            }
            $registrar = null;
            if (preg_match('/(?:registrar name|Registrar)\s*:\s*([^\r\n]+)/i', $raw, $rm) === 1) {
                $registrar = trim($rm[1]);
            }
            return [$expiry, $registrar];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
