<?php
/**
 * Validator for user-supplied AI endpoint base URLs.
 *
 * @package ThinkRank\AI
 * @since 2.8.0
 */

declare(strict_types=1);

namespace ThinkRank\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint URL Validator
 *
 * The OpenAI-compatible provider lets an administrator point ThinkRank at any
 * host that speaks the Chat Completions API — a local Ollama box, an Azure
 * deployment, a company gateway. That is a URL the plugin then fetches
 * server-side with the stored API key attached, so it is exactly the shape of
 * an SSRF: unchecked, it could be aimed at a cloud instance-metadata service
 * and used to read credentials out of the host.
 *
 * The rules here are deliberately narrow:
 *
 * - http:// is allowed only for loopback and RFC1918/RFC4193 private addresses
 *   (that is the whole point — a model on the same machine or LAN). Every
 *   public host must use https:// so the key is not sent in clear text.
 * - Link-local (169.254/16, fe80::/10) and the known cloud metadata hosts are
 *   refused outright, whether they are spelled as a name or an address.
 * - Credentials in the URL are refused; the API key has its own field.
 *
 * This is a validation gate for what gets *stored*, not a resolver: a public
 * hostname that happens to resolve to a private address still has to be https,
 * and the request itself is sent with redirects disabled so the key can never
 * follow a 302 to another host.
 *
 * @since 2.8.0
 */
class Endpoint_URL_Validator {

    /**
     * Hostnames that front a cloud instance-metadata service.
     *
     * @var string[]
     */
    private const BLOCKED_HOSTS = [
        'metadata.google.internal',
        'metadata.goog',
        'metadata',
        'instance-data',
    ];

    /**
     * Ceiling for a response body from an endpoint we do not control.
     *
     * Generous enough for the largest thing we ask for — a full content brief
     * as JSON — and far below what it takes to hurt a PHP worker.
     */
    private const MAX_RESPONSE_BYTES = 2097152; // 2 MB.

    /**
     * Literal addresses of instance-metadata services outside 169.254/16.
     *
     * @var string[]
     */
    private const BLOCKED_IPS = [
        '100.100.100.200', // Alibaba Cloud.
        'fd00:ec2::254',   // AWS IMDS over IPv6.
    ];

    /**
     * Validate and normalise a base URL for the OpenAI-compatible provider.
     *
     * @since 2.8.0
     *
     * @param string $url Raw URL as typed by the administrator.
     * @return string|\WP_Error Normalised URL (no trailing slash) or the reason it was refused.
     */
    public static function validate(string $url) {
        $url = trim($url);

        if ('' === $url) {
            return new \WP_Error(
                'thinkrank_endpoint_empty',
                __('Enter the base URL of your OpenAI-compatible endpoint, for example http://localhost:11434/v1', 'thinkrank')
            );
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return new \WP_Error(
                'thinkrank_endpoint_unparseable',
                __('That does not look like a URL. Include the scheme and host, for example http://localhost:11434/v1', 'thinkrank')
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return new \WP_Error(
                'thinkrank_endpoint_scheme',
                __('The endpoint URL must start with http:// or https://', 'thinkrank')
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return new \WP_Error(
                'thinkrank_endpoint_credentials',
                __('Remove the username and password from the URL. Enter the key in the API key field instead.', 'thinkrank')
            );
        }

        $host = strtolower((string) $parts['host']);
        // An IPv6 literal arrives wrapped in brackets.
        $bare_host = trim($host, '[]');
        // Judge ::ffff:a.b.c.d as the IPv4 address it reaches.
        $check_host = self::unmap_ipv4($bare_host);

        if (in_array($check_host, self::BLOCKED_HOSTS, true) || in_array($check_host, self::BLOCKED_IPS, true)) {
            return new \WP_Error(
                'thinkrank_endpoint_blocked',
                __('That host is a cloud metadata service, not an AI endpoint. Refusing to send requests there.', 'thinkrank')
            );
        }

        if (self::is_link_local($check_host)) {
            return new \WP_Error(
                'thinkrank_endpoint_blocked',
                __('Link-local addresses (169.254.x.x, fe80::) front instance metadata services and are not allowed.', 'thinkrank')
            );
        }

        if ('http' === $scheme && !self::is_local_host($check_host)) {
            return new \WP_Error(
                'thinkrank_endpoint_insecure',
                sprintf(
                    /* translators: %s: the host the administrator entered. */
                    __('Use https:// for %s. Plain http:// is only allowed for localhost and private network addresses, so your API key is never sent unencrypted over the internet.', 'thinkrank'),
                    $bare_host
                )
            );
        }

        return self::normalize($parts);
    }

    /**
     * Check the address a URL actually resolves to, at request time.
     *
     * {@see self::validate()} can only judge what was typed. A name is not an
     * address: `alias.internal` passes the spelling rules and can still resolve
     * to 169.254.169.254, which is the exact destination the guard exists to
     * refuse. So every request to a custom endpoint resolves the host first and
     * judges the addresses, not the label.
     *
     * The answer carries the address it approved, and
     * {@see self::guarded_request()} pins the connection to it — otherwise a
     * second lookup between this check and the socket (DNS rebinding) could
     * still land somewhere else.
     *
     * @since 2.8.0
     *
     * @param string $url URL about to be requested.
     * @return string|\WP_Error The approved IP, or the reason it was refused.
     */
    public static function resolve_safe_address(string $url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return new \WP_Error(
                'thinkrank_endpoint_unparseable',
                __('That does not look like a URL.', 'thinkrank')
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim(strtolower((string) $parts['host']), '[]');

        $addresses = self::addresses_for($host);

        if (empty($addresses)) {
            return new \WP_Error(
                'thinkrank_endpoint_unresolvable',
                sprintf(
                    /* translators: %s: the host that could not be resolved. */
                    __('Could not resolve %s. Check the endpoint host name.', 'thinkrank'),
                    $host
                )
            );
        }

        // Every address has to be acceptable, not just the first: a name that
        // answers with both a private address and a metadata address would
        // otherwise be a coin toss.
        foreach ($addresses as $address) {
            $check = self::unmap_ipv4($address);

            if (self::is_link_local($check) || in_array($check, self::BLOCKED_IPS, true)) {
                return new \WP_Error(
                    'thinkrank_endpoint_blocked',
                    sprintf(
                        /* translators: 1: host name, 2: the address it resolved to. */
                        __('%1$s resolves to %2$s, a link-local or cloud metadata address. Refusing to send requests there.', 'thinkrank'),
                        $host,
                        $address
                    )
                );
            }

            // Plain http is allowed for the local case only, and that has to be
            // true of the address as well as the name — `.internal` and
            // `.local` names are accepted on spelling alone by validate().
            if ('http' === $scheme && !self::is_local_address($check)) {
                return new \WP_Error(
                    'thinkrank_endpoint_insecure',
                    sprintf(
                        /* translators: 1: host name, 2: the public address it resolved to. */
                        __('%1$s resolves to the public address %2$s, so plain http:// is not allowed. Use https:// instead.', 'thinkrank'),
                        $host,
                        $address
                    )
                );
            }
        }

        return $addresses[0];
    }

    /**
     * Send a request to a custom endpoint with the destination checked and pinned.
     *
     * This is transport, not a provider call of its own: the clients that
     * route through it consult Spend_Guard before they get here, and the
     * Settings connection test uses it to probe a URL the administrator just
     * typed — charging that against the daily ceiling, or refusing it while
     * AI is paused, would stop them fixing the very setup the pause is about.
     *
     * @thinkrank-no-spend-guard
     *
     * @since 2.8.0
     *
     * @param string $url  Absolute URL.
     * @param array  $args wp_remote_request() arguments.
     * @return array|\WP_Error Response, or the reason the destination was refused.
     */
    public static function guarded_request(string $url, array $args) {
        $address = self::resolve_safe_address($url);
        if (is_wp_error($address)) {
            return $address;
        }

        $unpinnable = self::unpinnable_reason($url);
        if (is_wp_error($unpinnable)) {
            return $unpinnable;
        }

        // A server we do not control decides how much it sends back. Without a
        // ceiling the whole body is buffered before anything can reject it, so
        // a hostile or broken endpoint could spend the worker's memory (#721).
        if (!isset($args['limit_response_size'])) {
            $args['limit_response_size'] = self::MAX_RESPONSE_BYTES;
        }

        // Never follow a redirect: the key rides in the headers, and the
        // address approved above is only the address of this host.
        $args['redirection'] = 0;

        // Certificate verification is half of the pin for an https endpoint
        // (see self::unpinnable_reason()), so it is not a caller's to disable.
        $args['sslverify'] = true;

        $parts = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]');
        $port = (int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));

        // Pin the connection to the address that was just approved, so a second
        // DNS answer cannot send this request somewhere else (rebinding). The
        // Host header and TLS SNI still use the name, so certificates and
        // virtual hosts keep working. A no-op on a non-curl transport, which is
        // why the checks above stand on their own.
        $pin = static function ($handle) use ($host, $port, $address) {
            if (!function_exists('curl_setopt') || !defined('CURLOPT_RESOLVE')) {
                return;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pinning the resolved address is the point; wp_remote_*() has no equivalent, and this runs on the handle WordPress itself created.
            curl_setopt($handle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $address]);
        };

        add_action('http_api_curl', $pin, 10, 1);

        try {
            $response = wp_remote_request($url, $args);
        } finally {
            remove_action('http_api_curl', $pin, 10);
        }

        return $response;
    }

    /**
     * Refuse the request when the destination cannot be held to what was checked.
     *
     * The address check above happens before the socket opens, so something has
     * to guarantee the socket goes to the address that was approved. Two things
     * can do that:
     *
     * - cURL's CURLOPT_RESOLVE, which this class sets; or
     * - TLS itself. A rebound answer pointing at a metadata service cannot
     *   present a valid certificate for the name, so an https request fails
     *   before anything is sent. Certificate verification is therefore the pin,
     *   which is why https is allowed on any transport.
     *
     * That leaves one gap: plain http to a *name*, on an installation where
     * cURL is unavailable (WordPress then uses the streams transport, which
     * resolves again and offers no hook to pin). There we fail closed and ask
     * for the address instead of the name — nothing else in the request can
     * tell us we reached the host we vetted (#721).
     *
     * @since 2.8.0
     *
     * @param string $url URL about to be requested.
     * @return true|\WP_Error True when the destination can be held; the reason otherwise.
     */
    private static function unpinnable_reason(string $url) {
        $parts = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]');

        // https verifies the name against the certificate, and an IP literal
        // has no lookup to race.
        if ('http' !== $scheme || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (self::can_pin_destination()) {
            return true;
        }

        return new \WP_Error(
            'thinkrank_endpoint_unpinnable',
            sprintf(
                /* translators: %s: the host name the administrator entered. */
                __('This site cannot pin a plain-http connection to a verified address (cURL is unavailable), so %s has to be given as an IP address rather than a name, or use https://.', 'thinkrank'),
                $host
            )
        );
    }

    /**
     * Can this installation hold a connection to a chosen address?
     *
     * True when cURL is available with CURLOPT_RESOLVE, which is what
     * {@see self::guarded_request()} pins with. Filterable so a site that
     * routes HTTP through a transport of its own can state the answer, and so
     * the failure path is testable.
     *
     * @since 2.8.0
     *
     * @return bool
     */
    private static function can_pin_destination(): bool {
        $can_pin = function_exists('curl_init')
            && function_exists('curl_setopt')
            && defined('CURLOPT_RESOLVE');

        /**
         * Filters whether the destination of a custom AI endpoint request can be pinned.
         *
         * @since 2.8.0
         *
         * @param bool $can_pin Whether cURL with CURLOPT_RESOLVE is available.
         */
        return (bool) apply_filters('thinkrank_ai_endpoint_can_pin_destination', $can_pin);
    }

    /**
     * Resolve a host to the addresses it answers with.
     *
     * An IP literal resolves to itself. A name is looked up for both families;
     * a lookup that returns nothing is treated as a failure by the caller
     * rather than as "no bad addresses".
     *
     * @since 2.8.0
     *
     * @param string $host Lower-cased host without IPv6 brackets.
     * @return string[] Addresses, possibly empty.
     */
    private static function addresses_for(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        // dns_get_record() is absent or restricted on some hosts; a missing
        // AAAA answer is not an error, the A records above still decide.
        if (function_exists('dns_get_record')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is handled by the empty check below, not by a warning.
            $ipv6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($ipv6)) {
                foreach ($ipv6 as $record) {
                    if (!empty($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Is this *address* loopback or a private range?
     *
     * The name-based test below accepts `.local` and `.internal` on spelling;
     * this one is about what the name actually points at.
     *
     * @since 2.8.0
     *
     * @param string $address IPv4 or IPv6 address.
     * @return bool
     */
    private static function is_local_address(string $address): bool {
        if (in_array($address, ['127.0.0.1', '::1'], true) || str_starts_with($address, '127.')) {
            return true;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE);
        }

        return false;
    }

    /**
     * Is this host loopback, a private range, or a .local/.internal name?
     *
     * @since 2.8.0
     *
     * @param string $host Lower-cased host without IPv6 brackets.
     * @return bool True when plain http is acceptable for it.
     */
    private static function is_local_host(string $host): bool {
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }

        // host.docker.internal and friends: a name that can only resolve inside
        // the machine or its LAN.
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // 127/8 is all loopback, not just .0.1.
            if (str_starts_with($host, '127.')) {
                return true;
            }

            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE);
        }

        return false;
    }

    /**
     * Unwrap an IPv4-mapped IPv6 address (::ffff:a.b.c.d) to its IPv4 form.
     *
     * The mapped spelling reaches the IPv4 host, but matches none of the
     * IPv4 checks: `169.254.` prefixes, BLOCKED_IPS literals, or PHP's
     * private-range flags. Unwrapping first makes every check judge the
     * address the socket actually connects to.
     *
     * @since 2.8.0
     *
     * @param string $host Lower-cased host without IPv6 brackets.
     * @return string The IPv4 address, or the host unchanged.
     */
    private static function unmap_ipv4(string $host): string {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $host;
        }

        $packed = inet_pton($host);
        if (false === $packed || 16 !== strlen($packed) || str_repeat("\0", 10) . "\xff\xff" !== substr($packed, 0, 12)) {
            return $host;
        }

        return (string) inet_ntop(substr($packed, 12));
    }

    /**
     * Is this host a link-local address?
     *
     * FILTER_FLAG_NO_RES_RANGE would also reject loopback, which we want to
     * allow, so link-local is matched on its own.
     *
     * @since 2.8.0
     *
     * @param string $host Lower-cased host without IPv6 brackets.
     * @return bool
     */
    private static function is_link_local(string $host): bool {
        if (str_starts_with($host, '169.254.')) {
            return true;
        }

        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $normalized = strtolower((string) inet_ntop((string) inet_pton($host)));

        // fe80::/10 — fe80 through febf.
        return (bool) preg_match('/^fe[89ab][0-9a-f]:/', $normalized);
    }

    /**
     * Rebuild the URL from its parsed parts, dropping the fragment and any
     * trailing slash on the path.
     *
     * The query string is kept. Azure OpenAI requires `?api-version=…` on every
     * call, so discarding it silently turned the documented Azure URL into one
     * that 400s — which is why the route goes *before* the query (see
     * {@see self::route()}) rather than the base being concatenated blindly.
     *
     * @since 2.8.0
     *
     * @param array $parts Output of wp_parse_url().
     * @return string Normalised URL.
     */
    private static function normalize(array $parts): string {
        $url = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);

        if (!empty($parts['port'])) {
            $url .= ':' . (int) $parts['port'];
        }

        // Host and scheme are case-insensitive; a path and a query are not.
        $url .= isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        if (isset($parts['query']) && '' !== $parts['query']) {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    /**
     * Build the URL for one route against a stored base URL.
     *
     * A base URL may carry a query string (Azure's required `api-version`), so
     * the route has to be spliced in before it: '…/deployments/gpt4o' plus
     * 'chat/completions' plus '?api-version=2024-10-21', never
     * '…?api-version=2024-10-21/chat/completions'. Every caller — generation,
     * connection test, model listing, vision — goes through this so they cannot
     * drift apart (#721).
     *
     * @since 2.8.0
     *
     * @param string $base_url Stored (already validated) base URL.
     * @param string $route    Route to append, e.g. 'chat/completions'.
     * @return string Absolute URL.
     */
    public static function route(string $base_url, string $route): string {
        $base_url = trim($base_url);
        $query = '';

        $separator = strpos($base_url, '?');
        if (false !== $separator) {
            $query = substr($base_url, $separator + 1);
            $base_url = substr($base_url, 0, $separator);
        }

        $url = rtrim($base_url, '/') . '/' . ltrim($route, '/');

        return '' !== $query ? $url . '?' . $query : $url;
    }
}
