<?php /** The privacy policy: public, so Google (and anyone else) can read it without signing in. Expects $updated (string). */ ?>
<div class="card auth-card prose">
    <h1>Privacy policy</h1>
    <p class="muted small">For <?= e(app_name()) ?> · last updated <?= e($updated) ?></p>

    <p><strong>We do not share your information with anyone, and we have no intention of ever doing so.</strong>
        We do not sell it, rent it, trade it or hand it to advertisers, partners or other organizations. The only exception
        would be where the law requires us to disclose something.</p>

    <h2>What this site is</h2>
    <p><?= e(app_name()) ?> is a private tool our organization uses to keep track of its equipment and who is
        responsible for it. It is for the people we have given an account to.</p>

    <h2>What we keep</h2>
    <ul>
        <li><strong>Your account:</strong> your name, username, email address, department, and the permissions we gave you. Your password is
            stored only as a one-way scramble that nobody, including us, can turn back into the password.</li>
        <li><strong>Equipment records:</strong> the assets, who added or changed them and when, and any notes or files attached to them.</li>
        <li><strong>Sign-in details:</strong> when you last signed in, and a short-lived cookie that keeps you signed in on this site.</li>
    </ul>

    <h2>Signing in with Google</h2>
    <p>If you choose <em>Sign in with Google</em>, Google confirms to this site that you own your email address, and this
        site uses <strong>only that email address</strong> to find your existing account. We do not ask Google for access to your
        email, contacts, calendar, files or anything else in your Google account, and we do not store anything else Google
        sends. Google's handling of your sign-in is covered by
        <a href="https://policies.google.com/privacy" rel="noopener noreferrer">Google's privacy policy</a>.</p>

    <h2>How we use it</h2>
    <p>Only to run this site: to sign you in, to show you the equipment you are allowed to see, to keep a record of who changed
        what, and to send the occasional email about your account or about equipment changes. We do not use it for
        advertising, tracking or profiling. There are no analytics or advertising cookies here.</p>

    <h2>Who can see it</h2>
    <p>Only the administrators of this site, and other signed-in users within the limits of the permissions they have been
        given. Nobody outside the organization.</p>

    <h2>How long we keep it</h2>
    <p>For as long as your account exists, or as long as the equipment record is needed. When an account is removed, its
        sign-in details go with it.</p>

    <h2>Your choices</h2>
    <p>You can ask the site administrator what we hold about you, to correct it, or to remove your account.</p>

    <h2>Contact</h2>
    <p>Questions about this policy or your information: <a href="mailto:jwellis@carlalbert.edu">jwellis@carlalbert.edu</a>.</p>

    <h2>Changes</h2>
    <p>If this policy changes, the date at the top changes with it. Our promise not to share your information (apart from
        what the law requires) will not.</p>

    <p class="auth-alt"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
</div>
