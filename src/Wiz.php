<?php
namespace ClarionApp\WizlightBackend;

use ClarionApp\WizlightBackend\Transport\SocketUdpTransport;
use ClarionApp\WizlightBackend\Transport\UdpTransport;

/**
 * Protocol codec for the WiZ UDP protocol (FR-015).
 *
 * This class formats requests, sends them through an injectable transport,
 * decodes the responses, and returns plain arrays. It performs **no**
 * persistence: no model lookups, no save(), no knowledge that a database
 * exists. Deciding what to store — and storing it — belongs to the jobs and
 * services that own the data (BulbDiscovery, CheckBulbStatus, WizlightService).
 *
 * If you find yourself adding `use ...\Models\Bulb;` here, the change belongs
 * in a job or the service instead.
 */
class Wiz
{
    private string $broadcastAddress;
    private float $waitTime;
    private string $localIp;
    private string $phoneMac;
    private int $udpPort;
    private UdpTransport $transport;

    public function __construct(
        ?float $wait_time = null,
        ?string $broadcast_address = null,
        ?UdpTransport $transport = null
    ) {
        $this->broadcastAddress = $broadcast_address ?? config('wizlight.broadcast_address', '255.255.255.255');
        $this->waitTime = $wait_time ?? config('wizlight.udp_wait_time', 30.0);
        $this->phoneMac = config('wizlight.phone_mac', 'AAAAAAAAAAAA');
        $this->udpPort = config('wizlight.udp_port', 38899);
        $this->localIp = $this->get_local_ip();
        $this->transport = $transport ?? new SocketUdpTransport($this->broadcastAddress, $this->udpPort);
    }

    /**
     * Broadcast a registration request, then read state from each responder.
     *
     * Per light: getPilot, getSystemConfig, getModelConfig, then getUserConfig
     * as a conditional fallback when getModelConfig carries no usable cctRange.
     *
     * @return array<int, array{mac: string, ip: string, pilot_state: array, system_config: array, model_config?: array, user_config?: array}>
     */
    public function discover(): array
    {
        $bulbs = [];

        $message = new \stdClass();
        $message->method = 'registration';
        $message->params = new \stdClass();
        $message->params->phoneMac = $this->phoneMac;
        $message->params->register = false;
        $message->params->phoneIp = $this->localIp;
        $message->params->id = 1;

        $results = $this->send_udp($message);

        foreach ($results as $data) {
            $mac = $data['result']['mac'] ?? null;
            $from = $data['from'] ?? null;
            if (!$mac || !$from) {
                continue;
            }

            // getPilot and getSystemConfig first (existing callers expect this order).
            $pilotState = $this->firstResultPayload($this->get_pilot_state($from));
            $sysConfig = $this->firstResultPayload($this->get_system_config($from));

            // Then getModelConfig for capability data.
            $modelConfigResults = $this->get_model_config($from);
            $modelConfig = $this->firstResultPayloadAny($modelConfigResults);

            $bulb = [
                'mac' => $mac,
                'ip' => $from,
                'pilot_state' => $pilotState,
                'system_config' => $sysConfig,
                'model_config' => $modelConfig,
            ];

            // Fallback: if model_config has no usable cctRange, call getUserConfig.
            if (!$this->hasUsableCctRange($modelConfig)) {
                $userConfig = $this->firstResultPayloadAny($this->get_user_config($from));
                if (!empty($userConfig)) {
                    $bulb['user_config'] = $userConfig;
                }
            }

            $bulbs[] = $bulb;
        }

        return $bulbs;
    }

    /**
     * Pick the first datagram in a response set that carries a device payload.
     *
     * Requires a `mac` key in the result — appropriate for pilot, system_config,
     * and user_config responses that always include the device identifier.
     */
    private function firstResultPayload(array $results): array
    {
        foreach ($results as $result) {
            if (isset($result['result']) && isset($result['result']['mac'])) {
                return $result['result'];
            }
        }

        return [];
    }

    /**
     * Pick the first result payload regardless of shape.
     *
     * Used for model_config and other responses that may not carry a `mac` field.
     */
    private function firstResultPayloadAny(array $results): array
    {
        foreach ($results as $result) {
            if (isset($result['result'])) {
                return $result['result'];
            }
        }

        return [];
    }

    /**
     * Fade in/out, default dimming, power-on behaviour and white-range data.
     *
     * Retained but uncalled (FR-008b): the discovery path no longer issues this
     * round trip, and the later device-configuration phase is what consumes it.
     */
    public function get_user_config($ip): array
    {
        $message = new \stdClass();
        $message->method = 'getUserConfig';
        $message->params = new \stdClass();

        return $this->send_udp($message, $ip);
    }

    /**
     * Model config: cctRange, hardware revision, etc.
     *
     * Returns raw decoded response; cctRange shape (2-element vs 4-element)
     * is preserved — the caller decides which indices to read.
     */
    public function get_model_config($ip): array
    {
        $message = new \stdClass();
        $message->method = 'getModelConfig';
        $message->params = new \stdClass();

        return $this->send_udp($message, $ip);
    }

    /**
     * Check whether a model_config payload carries a usable cctRange.
     */
    private function hasUsableCctRange(array $modelConfig): bool
    {
        $cctRange = $modelConfig['cctRange'] ?? null;
        if (!is_array($cctRange)) {
            return false;
        }
        $len = count($cctRange);
        return $len === 2 || $len === 4;
    }

    public function get_system_config($ip): array
    {
        $message = new \stdClass();
        $message->method = 'getSystemConfig';
        $message->params = new \stdClass();

        return $this->send_udp($message, $ip);
    }

    public function get_pilot_state($ip): array
    {
        $pilot = new \stdClass();
        $pilot->method = 'getPilot';
        $pilot->params = new \stdClass();

        return $this->send_udp($pilot, $ip);
    }

    public function send_udp($message, $ips = null): array
    {
        $targets = $ips
            ? (is_array($ips) ? $ips : [$ips])
            : [$this->broadcastAddress];

        $this->transport->send($message, $targets);
        $results = $this->transport->receive($this->waitTime);
        $this->transport->close();

        return $results ?: [];
    }

    public function get_local_ip()
    {
        $base_url = config('app.url');
        // remove protocol and port from url
        $hostname = parse_url($base_url, PHP_URL_HOST);
        $ip = gethostbyname($hostname);
        // \Log::info("Local IP address: " . $ip);
        return $ip;
    }
}
