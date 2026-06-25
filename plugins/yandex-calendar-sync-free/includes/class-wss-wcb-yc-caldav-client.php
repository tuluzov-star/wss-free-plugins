<?php
if (!defined('ABSPATH')) {
    exit;
}

class WSS_WCB_YC_CalDAV_Client
{
    private string $calendar_url;
    private string $email;
    private string $password;
    private int $timeout;

    public function __construct(string $calendar_url, string $email, string $password, int $timeout = 25)
    {
        $this->calendar_url = trailingslashit(trim($calendar_url));
        $this->email        = trim($email);
        $this->password     = $password;
        $this->timeout      = $timeout;
    }

    public function test_connection()
    {
        $response = wp_remote_request($this->calendar_url, [
            'method'  => 'PROPFIND',
            'timeout' => $this->timeout,
            'headers' => array_merge($this->auth_headers(), [
                'Depth'        => '0',
                'Content-Type' => 'application/xml; charset=utf-8',
            ]),
            'body' => '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname /></d:prop></d:propfind>',
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (!in_array($code, [200, 207, 301, 302], true)) {
            return new WP_Error(
                'wss_wcb_yc_caldav_test_failed',
                sprintf('CalDAV вернул код %d. Ответ: %s', $code, wp_strip_all_tags(wp_remote_retrieve_body($response)))
            );
        }

        return true;
    }

    public function put_event(string $uid, string $ics)
    {
        $event_url = $this->event_url($uid);

        $response = wp_remote_request($event_url, [
            'method'      => 'PUT',
            'timeout'     => $this->timeout,
            'redirection' => 3,
            'headers'     => array_merge($this->auth_headers(), [
                'Content-Type' => 'text/calendar; charset=utf-8',
            ]),
            'body' => $ics,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (!in_array($code, [200, 201, 204], true)) {
            return new WP_Error(
                'wss_wcb_yc_caldav_put_failed',
                sprintf('Не удалось записать событие в Яндекс.Календарь. Код %d. Ответ: %s', $code, wp_strip_all_tags(wp_remote_retrieve_body($response)))
            );
        }

        return [
            'url'  => $event_url,
            'etag' => wp_remote_retrieve_header($response, 'etag'),
            'code' => $code,
        ];
    }

    private function event_url(string $uid): string
    {
        $filename = rawurlencode($uid) . '.ics';
        return trailingslashit($this->calendar_url) . $filename;
    }

    private function auth_headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->email . ':' . $this->password),
            'User-Agent'    => 'WSS-WooCommerce-Bookings-Yandex-Calendar/' . WSS_WCB_YC_VERSION,
        ];
    }
}
