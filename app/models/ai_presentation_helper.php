<?php

/**
 * Role-aware presentation data for the GSO Assistant widget.
 *
 * Presentation only. Nothing here grants capability: which chips a user sees
 * has no bearing on what the backend will answer, because every live query is
 * re-authorized server-side against the session user. A borrower who types an
 * admin-style question still gets borrower-scoped results.
 *
 * Deliberately dependency-free. The widget renders on every authenticated
 * page, so this must not drag the live-data, provider or orchestration stacks
 * into a normal page render.
 *
 * Every authenticated Phase 1 role has a presentation preset. Backend live
 * data authorization remains authoritative and is not granted by these chips.
 */

if (!function_exists("gsoChatPresentationPresets")) {
    function gsoChatPresentationPresets(): array
    {
        // Prefill chips exist because a live availability question needs a
        // subject. Sending "check resource availability" on its own only ever
        // produces "I couldn't find a matching resource", so those chips seed
        // the input and let the user name the item instead.
        $checkResource = [
            "label" => "Check Resource",
            "prompt" => "Is the ",
            "mode" => "prefill",
            "icon" => "fa-box",
        ];
        $checkFacility = [
            "label" => "Check Facility",
            "prompt" => "Is the ",
            "mode" => "prefill",
            "icon" => "fa-building",
        ];

        return [
            "Borrower" => [
                "title" => "GSO Assistant",
                "subtitle" => "Resources, requests & GSO guidance",
                "welcome_heading" => "Hi! How can I help you today?",
                "welcome_body" => "I can check your requests and incidents, look up resources and"
                    . " facilities, and explain GSO procedures.",
                "placeholder" => "Ask about resources, requests or procedures\u{2026}",
                "prompts" => [
                    $checkResource,
                    $checkFacility,
                    [
                        "label" => "My Request Status",
                        "prompt" => "What is the status of my request?",
                        "mode" => "send",
                        "icon" => "fa-clipboard-list",
                    ],
                    [
                        "label" => "My Incident Status",
                        "prompt" => "What is the status of my incident report?",
                        "mode" => "send",
                        "icon" => "fa-triangle-exclamation",
                    ],
                    [
                        "label" => "How to Borrow",
                        "prompt" => "How do I borrow equipment?",
                        "mode" => "send",
                        "icon" => "fa-hand-holding-hand",
                    ],
                    [
                        "label" => "Report an Issue",
                        "prompt" => "How do I report an issue?",
                        "mode" => "send",
                        "icon" => "fa-flag",
                    ],
                ],
            ],

            "GSO Staff" => [
                "title" => "GSO Assistant",
                "subtitle" => "Operational GSO guidance",
                "welcome_heading" => "How can I help with today's operations?",
                "welcome_body" => "I can help with resources, ordinary requests, release, returns, and maintenance procedures.",
                "placeholder" => "Ask about requests or operations…",
                "prompts" => [$checkResource, $checkFacility, [
                    "label" => "Maintenance Status", "prompt" => "Check maintenance status",
                    "mode" => "send", "icon" => "fa-screwdriver-wrench",
                ]],
            ],

            "Director" => [
                "title" => "GSO Assistant",
                "subtitle" => "GSO policy guidance",
                "welcome_heading" => "How can I help?",
                "welcome_body" => "Director live-data use cases are not enabled yet, but I can explain approved GSO procedures.",
                "placeholder" => "Ask about GSO procedures…",
                "prompts" => [[
                    "label" => "GSO Procedures", "prompt" => "Explain the GSO borrowing procedure",
                    "mode" => "send", "icon" => "fa-circle-info",
                ]],
            ],

            "Admin" => [
                "title" => "GSO Assistant",
                "subtitle" => "GSO operations & information",
                "welcome_heading" => "Hi! How can the GSO Assistant help?",
                "welcome_body" => "I can check resources, facilities, requests, incidents and"
                    . " maintenance, and explain GSO procedures.",
                "placeholder" => "Ask about inventory, requests or maintenance\u{2026}",
                "prompts" => [
                    $checkResource,
                    $checkFacility,
                    [
                        "label" => "Request Status",
                        "prompt" => "Check request status",
                        "mode" => "send",
                        "icon" => "fa-clipboard-list",
                    ],
                    [
                        "label" => "Incident Status",
                        "prompt" => "Check incident status",
                        "mode" => "send",
                        "icon" => "fa-triangle-exclamation",
                    ],
                    [
                        "label" => "Maintenance Status",
                        "prompt" => "Check maintenance status",
                        "mode" => "send",
                        "icon" => "fa-screwdriver-wrench",
                    ],
                    [
                        "label" => "Reports Help",
                        "prompt" => "How do I use the GSO reports?",
                        "mode" => "send",
                        "icon" => "fa-chart-column",
                    ],
                ],
            ],
        ];
    }
}

if (!function_exists("gsoChatPresentation")) {
    /**
     * Presentation preset for an authenticated role.
     *
     * $role must come from the server session. An unrecognised role falls back
     * to the least-privileged preset rather than the richest one.
     */
    function gsoChatPresentation(?string $role): array
    {
        $presets = gsoChatPresentationPresets();
        $key = (string) $role;

        return $presets[$key] ?? $presets["Borrower"];
    }
}

if (!function_exists("gsoChatPresentationModes")) {
    /** Chip behaviours the widget knows how to handle. */
    function gsoChatPresentationModes(): array
    {
        return ["send", "prefill"];
    }
}
