<?php

declare(strict_types=1);

namespace HubOS\Modules\Assets;

interface AssetInspector
{
    /**
     * Inspect a domain/hostname for DNS records, SSL certificate, and WHOIS expiry.
     *
     * @return array{
     *     host: string,
     *     dns_a: list<string>,
     *     dns_ns: list<string>,
     *     dns_mx: list<string>,
     *     ssl_status: string,
     *     ssl_expiry_date: ?string,
     *     ssl_issuer: ?string,
     *     ssl_subject: ?string,
     *     whois_expiry_date: ?string,
     *     whois_registrar: ?string,
     *     inspected_at: string
     * }
     */
    public function inspect(string $domain): array;
}
