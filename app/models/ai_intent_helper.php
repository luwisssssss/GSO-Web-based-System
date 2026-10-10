<?php

/**
 * Controlled intent routing for the chatbot.
 *
 * Classification is deterministic keyword matching rather than something the
 * language model decides, so routing cannot be talked out of its decision by
 * the contents of a user message.
 *
 * Step 9 answers the knowledge intents. The live-data intents are recognised
 * and routed here so Step 10 can attach controlled services to them without
 * reshaping the pipeline.
 */

if (!function_exists("gsoAiIntentCatalog")) {
    /**
     * intent => [live, patterns]
     *
     * "live" marks an intent that needs transactional data, which Step 9 does
     * not read. Order matters: the first intent with a match wins, so the
     * specific live intents are listed before the broader knowledge ones.
     */
    function gsoAiIntentCatalog(): array
    {
        return [
            "request_status" => [
                "live" => true,
                "patterns" => [
                    "status of my request", "my request status", "request approved", "approved yet",
                    "my borrowing request", "status of my borrow", "did my request", "was my request",
                    "my pending request", "track my request", "status ng request", "request ko", "approved na ba", "nasaan na yung return", "my return status",
                    // Action phrasings that carry no "my" and no reference number.
                    // Deliberately anchored on a verb: a bare "request status"
                    // would also swallow "what do the request statuses mean?",
                    // which is a knowledge-base question, not a record lookup.
                    "check request status", "check the request status", "check status of request",
                    "look up request status", "request status check", "view request status",
                ],
            ],
            "incident_status" => [
                "live" => true,
                "patterns" => [
                    "my incident", "incident status", "status of my report", "my report status",
                    "what happened to my incident", "issue i reported", "my reported issue",
                ],
            ],
            "maintenance_status" => [
                "live" => true,
                "patterns" => [
                    "been repaired", "is it repaired", "repair status", "maintenance status",
                    "still under maintenance", "fixed yet", "done repairing",
                ],
            ],
            "facility_availability" => [
                "live" => true,
                "patterns" => [
                    "is the avr", "avr available", "conference room available", "room available",
                    "facility available", "is the hall", "book the room", "free at",
                    "available tomorrow", "available today", "is the gym",
                ],
            ],
            "resource_availability" => [
                "live" => true,
                "patterns" => [
                    "is the projector", "projector available", "how many chairs", "how many available",
                    "chairs available", "stock left", "any available", "units available",
                    "do you have any", "is there any available",
                ],
            ],
            "faq_rescheduling" => ["live"=>false,"patterns"=>["reschedule","rescheduling","change reservation","change my schedule","palit schedule"]],
            "faq_director_role" => ["live"=>false,"patterns"=>["director","adviser"]],
            "navigation_help" => [
                "live" => false,
                "patterns" => [
                    "where do i", "where can i", "how do i open", "how do i find", "which page",
                    "where is the page", "navigate", "where to submit", "where to see",
                ],
            ],
            "faq_borrowing" => [
                "live" => false,
                "patterns" => [
                    "borrow", "borrowing", "request equipment", "request a resource", "loan",
                    "reserve equipment", "requirements to borrow",
                ],
            ],
            "faq_facility" => [
                "live" => false,
                "patterns" => [
                    "reserve a facility", "facility reservation", "book a facility", "reserve room",
                    "reserve the avr", "facility request", "venue",
                ],
            ],
            "faq_return" => [
                "live" => false,
                "patterns" => [
                    "return", "returning", "bring back", "give back", "overdue", "due date",
                ],
            ],
            "faq_incident" => [
                "live" => false,
                "patterns" => [
                    "incident", "report damage", "report a broken", "broken", "damaged", "lost item",
                    "missing part", "report an issue", "report issue",
                ],
            ],
            "faq_maintenance" => [
                "live" => false,
                "patterns" => [
                    "maintenance", "repair", "servicing", "under repair", "fix equipment",
                ],
            ],
            "greeting" => [
                "live" => false,
                "patterns" => [
                    "hello", "hi there", "good morning", "good afternoon", "good evening",
                    "kumusta", "thank you", "thanks",
                ],
            ],
        ];
    }
}

if (!function_exists("gsoAiLiveIntents")) {
    /** Intents that require transactional data; deferred to Step 10. */
    function gsoAiLiveIntents(): array
    {
        $live = [];

        foreach (gsoAiIntentCatalog() as $intent => $config) {
            if (!empty($config["live"])) {
                $live[] = $intent;
            }
        }

        return $live;
    }
}

if (!function_exists("gsoClassifyChatIntent")) {
    /**
     * Route one user message.
     *
     * Returns intent, whether it needs live data, and the matched phrase for
     * logging. Anything unrecognised becomes "faq_general", which still goes
     * through knowledge retrieval and falls back to an honest "I don't know".
     */
    function gsoClassifyChatIntent(string $message): array
    {
        $normalized = strtolower(trim($message));
        $normalized = preg_replace('/\s+/', " ", $normalized) ?? "";

        if ($normalized === "") {
            return ["intent" => "faq_general", "live" => false, "matched" => ""];
        }

        foreach (gsoAiIntentCatalog() as $intent => $config) {
            foreach ((array) ($config["patterns"] ?? []) as $pattern) {
                if (strpos($normalized, $pattern) !== false) {
                    return [
                        "intent" => $intent,
                        "live" => (bool) ($config["live"] ?? false),
                        "matched" => $pattern,
                    ];
                }
            }
        }

        return ["intent" => "faq_general", "live" => false, "matched" => ""];
    }
}

if (!function_exists("gsoAiLiveIntentDeferralMessage")) {
    /**
     * Step 9 recognises live questions but does not read transactional data.
     * Point the user at the page that already answers it, rather than guessing.
     */
    function gsoAiLiveIntentDeferralMessage(string $intent, string $role): string
    {
        $isAdmin = $role === "Admin";

        $pages = [
            "request_status" => $isAdmin
                ? "Open Requests in the admin sidebar to review the current request pipeline."
                : "Open \"My Requests\" in your sidebar to see the live status of every request you submitted.",
            "incident_status" => $isAdmin
                ? "Open Incident Reports in the admin sidebar to see reported issues and their status."
                : "Open \"My Reports\" in your sidebar to see the status of the issues you reported.",
            "maintenance_status" => $isAdmin
                ? "Open Maintenance in the admin sidebar for current schedules and their status."
                : "Open \"Browse Resources\" and check the item's status; resources under repair are marked there.",
            "facility_availability" => $isAdmin
                ? "Open Requests to review reservations, or Inventory for facility details."
                : "Open \"Browse Resources\", pick the facility, then choose your date and time. The system checks for schedule conflicts before it accepts the reservation.",
            "resource_availability" => $isAdmin
                ? "Open Inventory in the admin sidebar for live stock figures."
                : "Open \"Browse Resources\" to see the available quantity for each item.",
        ];

        $pointer = $pages[$intent] ?? "You can find this in your sidebar.";

        return "I can't read live records yet, so I won't guess at the answer. " . $pointer;
    }
}
