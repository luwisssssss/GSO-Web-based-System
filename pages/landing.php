<?php
session_start();
require_once "../includes/role_helper.php";

if (isset($_SESSION["user_id"])) {
    redirectToRoleHome($_SESSION["role"] ?? null, "../");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>General Services Office Borrowing System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/landing.css?v=20260419-formal-landing">
<link rel="stylesheet" href="../assets/css/footer.css?v=20260419-formal-footer">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>
<header class="navbar" id="navbar">
    <div class="container nav">

        <a class="logo" href="#home" aria-label="General Services Office  home">
            <span class="logo-frame"><img src="../assets/css/img/logo.png" alt="GSO logo"></span>
            <span>General Services Office </span>
        </a>

        <nav class="nav-links" aria-label="Landing page navigation">
            <a href="#about">About</a>
            <a href="#features">Features</a>
            <a href="#rules">House Rules</a>
            <a href="#updates">Highlights</a>
        </nav>

        <a class="nav-login" href="../auth/login.php">
            <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
            Login
        </a>

    </div>
</header>


<section class="hero" id="home">

    <video autoplay muted loop playsinline class="bg-video" aria-hidden="true">
        <source src="../assets/css/img/video_bg.mp4" type="video/mp4">
    </video>

    <div class="overlay"></div>

    <div class="container hero-layout">
        <div class="hero-copy">
            <span class="eyebrow">Centralized Institutional Resource Management</span>
            <h1>Web-Based GSO Borrowing & Inventory Management System</h1>

            <p>
                A centralized digital platform designed to streamline the borrowing, tracking, and management of university facilities, equipment, and resources efficiently and securely.
            </p>

            <div class="hero-buttons">
                <a href="../auth/signup.php" class="btn-primary">
                    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    Request Access
                </a>
                <a href="../auth/login.php" class="btn-outline">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Login
                </a>
            </div>

            <div class="hero-metrics">
                <article class="hero-metric">
                    <strong>Structured Request Handling</strong>
                    <span>Formalized workflows for submission, review, release, and return.</span>
                </article>
                <article class="hero-metric">
                    <strong>Secure Accountability</strong>
                    <span>Central records support transparency, traceability, and authorized access.</span>
                </article>
                <article class="hero-metric">
                    <strong>Operational Visibility</strong>
                    <span>Borrowing, maintenance, and reporting data remain accessible in one platform.</span>
                </article>
            </div>

        </div>

    </div>

</section>

<section class="about-showcase" id="about">

    <div class="container about-content">

        <div class="about-brand-stack">
            <div class="about-logo-wrap">
                <img src="../assets/css/img/login_logo.png" class="about-logo" alt="GSO system brand mark">
            </div>
            <div class="about-eyebrow-wrap">
                <span class="eyebrow">About the Platform</span>
            </div>
        </div>

        <h2>Structured Resource Governance for University Operations</h2>

        <p class="about-desc">
            The General Services Office (GSO) Borrowing System provides a structured and efficient process for managing resource requests, ensuring transparency, accountability, and accessibility.
        </p>

        <div class="about-stats">

            <div class="stat-box">
                <i class="fas fa-boxes-stacked" aria-hidden="true"></i>
                <h3>Resource Oversight</h3>
                <span>Central monitoring of facilities, equipment, and institutional assets.</span>
            </div>

            <div class="stat-box">
                <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                <h3>Request Governance</h3>
                <span>Validated approval and release procedures for authorized transactions.</span>
            </div>

            <div class="stat-box">
                <i class="fas fa-rotate-left" aria-hidden="true"></i>
                <h3>Return Compliance</h3>
                <span>Condition reporting, submission review, and documented accountability.</span>
            </div>

            <div class="stat-box">
                <i class="fas fa-chart-line" aria-hidden="true"></i>
                <h3>Administrative Reporting</h3>
                <span>Operational summaries that support informed oversight and planning.</span>
            </div>

        </div>

        <div class="mission-panel">
            <span class="mission-label">Mission</span>
            <p>Our commitment is to deliver a seamless and efficient borrowing system that supports institutional operations while maintaining accountability and service excellence.</p>
        </div>

        <div class="rules-box" id="rules">

            <div class="rules-header">
                <h3>General House Rules</h3>
                <p>Observe these institutional reminders before requesting, borrowing, or returning General Services Office resources.</p>
            </div>

            <ul>
                <li>Submit requests with complete and accurate schedules, quantities, and intended purpose.</li>
                <li>Only authorized users may operate specialized equipment or occupy reserved facilities.</li>
                <li>Return borrowed resources on or before the approved due date.</li>
                <li>Report damaged, missing, or incomplete resources immediately for proper assessment.</li>
                <li>Maintain facilities in an orderly, secure, and service-ready condition after use.</li>
                <li>Comply with all release, inspection, and return instructions issued by the General Services Office.</li>
            </ul>

        </div>

    </div>

</section>

<section class="key-features" id="features">

    <div class="container key-grid">

        <div class="profile-side">
            <img src="../assets/css/img/admin_profile.jpg" class="profile-img" alt="General Services Office representative">

            <div class="profile-content">
                <span class="eyebrow">Leadership and System Highlights</span>
                <h2>Leadership and System Highlights</h2>
                <h4>GSO Head: Unknown </h4>

                <p>
                    Under the leadership of the General Services Office, this system enhances operational efficiency and resource management within the institution.
                </p>

                <ul class="leadership-highlights">
                    <li>Supports organized service delivery for borrowing and return transactions.</li>
                    <li>Strengthens documentation, monitoring, and institutional accountability.</li>
                    <li>Provides a reliable platform for resource coordination across the university.</li>
                </ul>
            </div>
        </div>

        <div class="features-side">

            <span class="eyebrow">Core Capabilities</span>
            <h2>Functional modules aligned with actual office processes.</h2>
            <p class="feature-sub">
                Each module is designed to support a formal institutional workflow, from resource discovery and request submission to release authorization, maintenance scheduling, return evaluation, and reporting.
            </p>

            <div class="feature-grid">

                <div class="feature-card">
                    <i class="fas fa-boxes-stacked blue" aria-hidden="true"></i>
                    <h3>Inventory Control</h3>
                    <p>Maintain organized records of stock levels, availability, location, category, and operational condition.</p>
                </div>

                <div class="feature-card">
                    <i class="fas fa-building blue" aria-hidden="true"></i>
                    <h3>Facility Scheduling</h3>
                    <p>Coordinate reservations for facilities through validated dates, times, and conflict-aware scheduling.</p>
                </div>

                <div class="feature-card">
                    <i class="fas fa-list-check blue" aria-hidden="true"></i>
                    <h3>Request Processing</h3>
                    <p>Support submission, approval, rejection, release, and status tracking through an auditable workflow.</p>
                </div>

                <div class="feature-card">
                    <i class="fas fa-shield-halved blue" aria-hidden="true"></i>
                    <h3>Role-Based Access</h3>
                    <p>Separate borrower and administrator responsibilities through secure, role-specific workspaces.</p>
                </div>

                <div class="feature-card">
                    <i class="fas fa-screwdriver-wrench blue" aria-hidden="true"></i>
                    <h3>Maintenance Monitoring</h3>
                    <p>Schedule and track inspection, repair, and maintenance activities that affect resource availability.</p>
                </div>

                <div class="feature-card">
                    <i class="fas fa-chart-pie blue" aria-hidden="true"></i>
                    <h3>Reports and Audit Logs</h3>
                    <p>Generate operational summaries and preserve traceable records for oversight, review, and compliance.</p>
                </div>

            </div>

        </div>

    </div>

</section>

<section class="updates-section" id="updates">
    <div class="container updates-grid">
        <div class="section-copy">
            <span class="eyebrow">Operational Highlights</span>
            <h2>Designed to strengthen coordination and institutional control.</h2>
            <p>
                Administrators are provided with a consolidated view of resource requests, active borrowings, return submissions, maintenance schedules, and inventory conditions in support of timely decision-making.
            </p>
        </div>

        <div class="announcement-list">
            <article>
                <span><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
                <div>
                    <h3>Status Notifications</h3>
                    <p>Borrowers and administrators receive updates on approvals, releases, returns, and critical alerts.</p>
                </div>
            </article>
            <article>
                <span><i class="fa-solid fa-file-export" aria-hidden="true"></i></span>
                <div>
                    <h3>Report Generation</h3>
                    <p>Administrative records can be prepared for office review, documentation, and institutional reporting.</p>
                </div>
            </article>
            <article>
                <span><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                <div>
                    <h3>Activity Traceability</h3>
                    <p>Key transactions remain visible through audit-ready activity histories and submission records.</p>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="cta-section">
    <div class="container cta-panel">
        <div>
            <span class="eyebrow">Secure Access</span>
            <h2>Access the General Services Office workspace.</h2>
            <p>Sign in to manage requests, monitor resources, and perform authorized borrowing transactions.</p>
        </div>
        <div class="hero-buttons">
            <a href="../auth/login.php" class="btn-primary">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                Login
            </a>
            <a href="../auth/signup.php" class="btn-outline dark">
                <i class="fa-solid fa-user-check" aria-hidden="true"></i>
                Create Account
            </a>
        </div>
    </div>
</section>

<?php include "../includes/footer.php"; ?>

<script>
const animatedSelectors = [
    ".hero-copy > .eyebrow",
    ".hero-copy > h1",
    ".hero-copy > p",
    ".hero-buttons",
    ".hero-metric",
    ".about-brand-stack",
    ".about-content > h2",
    ".about-desc",
    ".stat-box",
    ".mission-panel",
    ".profile-side",
    ".features-side > .eyebrow",
    ".features-side > h2",
    ".feature-sub",
    ".feature-card",
    ".rules-header",
    ".rules-box li",
    ".section-copy",
    ".announcement-list article",
    ".cta-panel"
];

const animatedElements = animatedSelectors.flatMap(function (selector) {
    return Array.from(document.querySelectorAll(selector));
});

if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver(function (entries, observer) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add("is-visible");
            observer.unobserve(entry.target);
        });
    }, {
        threshold: 0.14,
        rootMargin: "0px 0px -70px 0px"
    });

    animatedElements.forEach(function (element, index) {
        element.classList.add("reveal-on-scroll");
        element.style.setProperty("--reveal-delay", Math.min((index % 6) * 70, 350) + "ms");
        revealObserver.observe(element);
    });
} else {
    animatedElements.forEach(function (element) {
        element.classList.add("is-visible");
    });
}

let ticking = false;

function updateScrollEffects() {
    const navbar = document.getElementById("navbar");
    const heroVideo = document.querySelector(".bg-video");

    if (window.scrollY > 50) {
        navbar.classList.add("scrolled");
    } else {
        navbar.classList.remove("scrolled");
    }

    if (heroVideo) {
        heroVideo.style.setProperty("--hero-video-y", Math.min(window.scrollY * 0.06, 30) + "px");
    }

    ticking = false;
}

function requestScrollUpdate() {
    if (ticking) {
        return;
    }

    window.requestAnimationFrame(updateScrollEffects);
    ticking = true;
}

updateScrollEffects();
window.addEventListener("scroll", requestScrollUpdate, { passive: true });
</script>
</body>
</html>
