# Moodle 4.5 / PHP 8.1–8.2 review

This is an authentication / SAML identity-provider plugin (`auth_samlidp`), not an enrolment plugin. Reviewed the current checkout and all five commits since January 2022.

## Supplied patch versus Ivan Matviyuk's changes

| Patch change | Existing history / decision |
| --- | --- |
| Square-bracket cookie/config offsets in auth.php | Already implemented by ca0ec41 (2024-02-23). |
| Square-bracket offsets in the SimpleSAMLphp module | Already implemented by ca0ec41, which also moved lib to src and www to public and migrated to namespaced SimpleSAMLphp APIs. The patch targets the obsolete path. |
| Square-bracket privacy profile-field offset | Missing from ca0ec41. Applied: curly-brace offsets were removed in PHP 8.0 and cause a parse error when privacy discovery loads this class. |
| Two attribute-map assignments changed to `$userattr = array($value)` | Rejected: each iteration discards the previous attributes and their names. Ivan's indexed assignments already implement the correct fix. |
| Comment corrections | Applied where relevant. |

Commit 3b60cd3 (2022-05-27) replaced string assertions with boolean expressions; 9acfed9 and 2c85ae2 only changed version numbers. Commit 3146084 updated the changelog. Preserve these changes. There is no measured performance improvement from this patch: its useful changes address parsing/compatibility, not execution speed.

## Additional fixes

- Default the unset SimpleSAMLphp root during construction, avoiding PHP 8 undefined-property warnings on a newly installed/unconfigured plugin.
- Fully qualify SimpleSAMLphp exception classes. Relative names inside the module namespace otherwise cause class-not-found failures; the legacy BadRequest name is also obsolete.
- Reject array/empty State parameters and array authentication cookies before string operations (PHP 8 type errors).
- Use the saved `moodle:AuthID` in the missing-source error; `self::AUTHID` is undefined in SimpleSAMLphp 2.0.
- Return no identity for missing, deleted or suspended users before calling Moodle 4.5's typed `profile_user_record(int $userid, ...)`. Previously missing users caused a property warning and a TypeError; disabled accounts could still be exported.
- Initialise the attribute map and preserve all existing named standard/custom attributes and credential exclusions.
- Guard Moodle bootstrap constants when already defined; retain the original bootstrap mode.
- Use `hash_equals` for cookie verification while preserving the existing HMAC format.
- Pass ReturnTo as a parameter to SimpleSAMLphp's URL encoder. Previously login appended it twice, and logout inserted it unescaped, corrupting nested query strings and existing endpoint parameters.
- Document explicit module enablement and trusted-host configuration required by the 2.x migration.

Moodle 4.5's authentication hook signatures still match these overrides. Its base class already declares config/authtype; the plugin does not introduce PHP 8.2 dynamic properties there. Exported users and Moodle session objects are stdClass, where dynamic properties remain supported. No additional removed Moodle API call was identified in this small plugin. The declared Moodle minimum remains 4.3, as set by Ivan; this checkout is not declared installable on 4.1.

## Validation and limits

All PHP files pass syntax checks on the available PHP 8.1.5 CLI. Standalone regression checks pass with E_ALL warnings converted to exceptions: invalid/missing State, missing source, malformed/bad-HMAC cookies, missing/deleted/suspended users, preservation of user/custom attributes, credential exclusion, repeat bootstrap, and login/logout parameter delegation. The checks stub dependency APIs; they do not prove real SAML/session integration. No PHP 8.2 runtime or complete Moodle/SimpleSAMLphp test installation was available, so PHP 8.2 and full integration remain unverified.

Before production rollout, run `php -n tests/compatibility.php` and lint on both target PHP versions, then test on Moodle 4.5 with your installed SimpleSAMLphp release: new login, already logged-in login, custom-profile exports, nested ReturnTo query strings, SP-initiated logout, missing/disabled users and privacy metadata discovery. Use a PHP-compatible SimpleSAMLphp release, enable the moodle module, keep the SQL session store and full-URL baseurlpath, and ensure the cookie path covers both applications. Check Composer/autoloader interactions in the real deployment.

## Further issues requiring a separate design change

These predate Moodle 4.5 and are not resolved by syntax compatibility fixes:

- Moodle's login/logout hooks accept arbitrary valid ReturnTo URLs without an allowlist. Constrain these to the configured SimpleSAMLphp resume/logout destinations before exposing the deployment publicly; the right allowlist depends on the IdP URL and logout flow.
- The HMAC cookie has no server-enforced expiry or one-time replay protection, and its creation lacks explicit Secure/HttpOnly/SameSite options. A captured cookie remains replayable; changing its format requires coordinated changes on both sides and browser SSO testing.
- The custom logout endpoint deliberately omits sesskey validation, permitting logout CSRF. Replacing it requires a verified SAML logout handoff rather than blindly adding Moodle's sesskey requirement.
- The module exports almost the entire user record and all custom profile fields. Review the IdP attribute release policy; narrowing it could break existing SP mappings.
- Moodle bootstrap from a SimpleSAMLphp request and explicit PHP-session destruction remain integration-sensitive. Do not claim support for all later Moodle/SSP releases from this review.

## SimpleSAMLphp 2.2 class-loading errors (2026-10-30)

The 2.2 `Module::resolveClass()` constructs the module class name and calls `is_subclass_of()` without first checking `class_exists()`. Therefore "isn't a subclass" also means the class could not be autoloaded. This checkout's External already extends `\SimpleSAML\Auth\Source`; changing that inheritance is not a fix. The exact server cause cannot be established without inspecting its deployed files.

The 2.2 module autoloader expects exactly:

```
<simplesaml-root>/modules/moodle/src/Auth/Source/External.php
<simplesaml-root>/modules/moodle/public/resume.php
```

Copy or symlink the plugin's **moodle subdirectory** as `modules/moodle`, not the entire auth_samlidp directory. Replace obsolete `lib/Auth/Source` and `www` deployments with the current `src` and `public` layout. Preserve configuration, check case-sensitive names and web-server read/traverse permissions, and enable `'moodle' => true` inside `'module.enable'`. If SimpleSAMLphp has a custom module directory, use that directory instead of the default modules path. No Composer mapping is required for this manually installed module: 2.2 registers its own module autoloader.

Run on the IdP host with its PHP runtime and SimpleSAMLphp configuration environment:

```sh
php /path/to/moodle/auth/samlidp/tools/check-simplesaml.php /path/to/simplesamlphp
```

It reports the resolved module directory, the actual loaded class file and parent, and runs the real SimpleSAMLphp resolver without authenticating a user. A CLI pass with a continuing web error points to differing configuration, permissions, PHP installations or stale OPcache; reload the relevant PHP-FPM/Apache workers after deploying the correct files. CLI does not prove that the web worker can read the same paths.

A separate confirmed incompatibility is fixed here: Moodle's auth plugin now prefers **src/_autoload.php**, the entry point provided by SimpleSAMLphp 2.2, with lib/_autoload.php retained as a fallback. Set the Moodle SimpleSAMLphp code-root setting to the installation root, not public/. The regression suite checks both path selections. The supplied subclass error happens on the IdP side before this Moodle callback, so this autoloader-path fix alone does not resolve a wrongly deployed IdP module.

Inspected upstream 2.2 `Module.php`, `_autoload_modules.php`, `_autoload.php` and `Auth/Source.php`. Its authenticate/logout signatures match this module. Syntax checks and standalone regression checks pass on PHP 8.1.5; the user's live installation has not been accessed.

Upstream 2.2 sources:
- https://github.com/simplesamlphp/simplesamlphp/blob/simplesamlphp-2.2/src/SimpleSAML/Module.php
- https://github.com/simplesamlphp/simplesamlphp/blob/simplesamlphp-2.2/src/_autoload_modules.php
- https://github.com/simplesamlphp/simplesamlphp/blob/simplesamlphp-2.2/src/_autoload.php

## Primary sources

- PHP 8.0 removed curly-brace offsets: https://www.php.net/manual/en/migration80.incompatible.php
- Moodle 4.5 requirements: https://moodledev.io/general/releases/4.5
- Moodle 4.5 authentication hooks: https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/lib/authlib.php
- Moodle 4.5 profile API: https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/user/profile/lib.php
- SimpleSAMLphp 2.0 source/redirect API: https://github.com/simplesamlphp/simplesamlphp/tree/v2.0.0/src/SimpleSAML
- SimpleSAMLphp modules: https://simplesamlphp.org/docs/stable/simplesamlphp-modules
