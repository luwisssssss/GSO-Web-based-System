<?php

/**
 * AI provider abstraction.
 *
 * Every outbound model call goes through GsoAiProvider, so no controller or
 * page ever holds a raw API call or an API key. Swapping providers means
 * adding one class and changing AI_PROVIDER in .env.
 *
 * The provider is only ever asked to rephrase text the application already
 * selected. It is never given database access, tools, or credentials.
 */

require_once dirname(__DIR__, 2) . "/config/bootstrap.php";

if (!interface_exists("GsoAiProvider")) {
    interface GsoAiProvider
    {
        /** Short identifier used in logs. */
        public function name(): string;

        /** True when the provider has everything it needs to be called. */
        public function isConfigured(): bool;

        /**
         * @param string $systemPrompt Application-authored instructions.
         * @param array  $messages     [["role" => "user"|"assistant", "content" => string], ...]
         * @return array{success:bool,content:string,error:string,error_code:string}
         */
        public function generate(string $systemPrompt, array $messages): array;
    }
}

if (!function_exists("gsoAiProviderResult")) {
    function gsoAiProviderResult(bool $success, string $content = "", string $errorCode = "", string $error = ""): array
    {
        return [
            "success" => $success,
            "content" => $content,
            "error" => $error,
            "error_code" => $errorCode,
        ];
    }
}

if (!class_exists("GsoDisabledAiProvider")) {
    /**
     * Used when no provider is configured. The chatbot still works: answers
     * come straight from the knowledge base instead of being rephrased.
     */
    final class GsoDisabledAiProvider implements GsoAiProvider
    {
        public function name(): string
        {
            return "disabled";
        }

        public function isConfigured(): bool
        {
            return false;
        }

        public function generate(string $systemPrompt, array $messages): array
        {
            return gsoAiProviderResult(false, "", "provider_disabled", "No AI provider is configured.");
        }
    }
}

if (!class_exists("GsoDeepSeekProvider")) {
    /**
     * DeepSeek chat completions (OpenAI-compatible payload).
     *
     * The key is read from the environment at call time and never returned,
     * logged, or included in any response.
     */
    final class GsoDeepSeekProvider implements GsoAiProvider
    {
        private string $apiKey;
        private string $baseUrl;
        private string $model;
        private int $timeout;
        private float $temperature;
        private int $maxTokens;

        public function __construct()
        {
            $this->apiKey = trim((string) gsoEnv("AI_API_KEY", ""));
            $this->baseUrl = rtrim(trim((string) gsoEnv("AI_API_BASE_URL", "https://api.deepseek.com")), "/");
            $this->model = trim((string) gsoEnv("AI_MODEL", "deepseek-chat"));
            $this->timeout = max(3, min(60, (int) gsoEnv("AI_TIMEOUT_SECONDS", "20")));
            $this->temperature = 0.2;
            $this->maxTokens = max(64, min(1200, (int) gsoEnv("AI_MAX_TOKENS", "600")));
        }

        public function name(): string
        {
            return "deepseek";
        }

        public function isConfigured(): bool
        {
            return $this->apiKey !== "" && $this->baseUrl !== "" && $this->model !== "";
        }

        public function generate(string $systemPrompt, array $messages): array
        {
            if (!$this->isConfigured()) {
                return gsoAiProviderResult(false, "", "provider_unconfigured", "AI provider credentials are missing.");
            }

            if (!function_exists("curl_init")) {
                return gsoAiProviderResult(false, "", "provider_unavailable", "HTTP client unavailable.");
            }

            $payloadMessages = [["role" => "system", "content" => $systemPrompt]];

            foreach ($messages as $message) {
                $role = (string) ($message["role"] ?? "user");
                $content = trim((string) ($message["content"] ?? ""));

                if ($content === "" || !in_array($role, ["user", "assistant"], true)) {
                    continue;
                }

                $payloadMessages[] = ["role" => $role, "content" => $content];
            }

            $body = json_encode([
                "model" => $this->model,
                "messages" => $payloadMessages,
                "temperature" => $this->temperature,
                "max_tokens" => $this->maxTokens,
                "stream" => false,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($body)) {
                return gsoAiProviderResult(false, "", "request_encoding_failed", "Could not encode the AI request.");
            }

            $curl = curl_init($this->baseUrl . "/chat/completions");

            if ($curl === false) {
                return gsoAiProviderResult(false, "", "provider_unavailable", "Could not start the AI request.");
            }

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Accept: application/json",
                    "Authorization: Bearer " . $this->apiKey,
                ],
            ]);

            $raw = curl_exec($curl);
            $transportError = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (!is_string($raw) || $raw === "") {
                // Never surface the transport string to the user; it can carry host details.
                error_log("AI provider transport failure: " . $transportError);
                return gsoAiProviderResult(false, "", "provider_timeout", "The AI provider did not respond.");
            }

            if ($status === 401 || $status === 403) {
                error_log("AI provider rejected credentials (HTTP {$status}).");
                return gsoAiProviderResult(false, "", "provider_auth_failed", "The AI provider rejected the request.");
            }

            if ($status === 429) {
                return gsoAiProviderResult(false, "", "provider_rate_limited", "The AI provider is rate limiting requests.");
            }

            if ($status < 200 || $status >= 300) {
                error_log("AI provider returned HTTP {$status}.");
                return gsoAiProviderResult(false, "", "provider_error", "The AI provider returned an error.");
            }

            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                return gsoAiProviderResult(false, "", "provider_bad_response", "The AI provider returned an unreadable response.");
            }

            $content = trim((string) ($decoded["choices"][0]["message"]["content"] ?? ""));

            if ($content === "") {
                return gsoAiProviderResult(false, "", "provider_empty_response", "The AI provider returned an empty response.");
            }

            return gsoAiProviderResult(true, $content);
        }
    }
}

if (!function_exists("gsoAiProviderEnabled")) {
    function gsoAiProviderEnabled(): bool
    {
        return gsoEnvBool("AI_ENABLED", true);
    }
}

if (!function_exists("gsoResolveAiProvider")) {
    /**
     * Build the configured provider. Unknown or disabled configurations fall
     * back to the disabled provider so the chatbot degrades to knowledge-base
     * answers instead of failing.
     */
    function gsoResolveAiProvider(?string $override = null): GsoAiProvider
    {
        if (!gsoAiProviderEnabled()) {
            return new GsoDisabledAiProvider();
        }

        $provider = strtolower(trim($override ?? (string) gsoEnv("AI_PROVIDER", "")));

        switch ($provider) {
            case "deepseek":
                return new GsoDeepSeekProvider();
            case "":
            case "none":
            case "disabled":
                return new GsoDisabledAiProvider();
            default:
                error_log("Unknown AI_PROVIDER configured: " . $provider);
                return new GsoDisabledAiProvider();
        }
    }
}
