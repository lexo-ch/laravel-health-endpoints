# 1.0.0 (2026-11-02)


* feat!: add LHE_OUTPUT with statuses-only basic output by default ([65fe3a4](https://github.com/lexo-ch/laravel-health-endpoints/commit/65fe3a47a9add92b4124d370b13854e839a8a090))
* feat!: drop app version from ping, harden defaults and guidance ([25fd2b9](https://github.com/lexo-ch/laravel-health-endpoints/commit/25fd2b9bc1c4be8061a8a8aba16cb4248938780a))
* feat!: read app version from composer.json and always return full output ([80ee35a](https://github.com/lexo-ch/laravel-health-endpoints/commit/80ee35ab5f19cbed5bf5115fa7aa99cc682b0dff))
* feat!: slim ping to status and version, drop app info from reports ([6c8014b](https://github.com/lexo-ch/laravel-health-endpoints/commit/6c8014b9d1bcc7cc8b65eed58daf43e98c6aa907))
* feat!: use "error" status for rejected requests ([30b2610](https://github.com/lexo-ch/laravel-health-endpoints/commit/30b2610db745f7a1edccca18b4ab79b420a83889))


### Bug Fixes

* apply per-driver probe timeouts and isolate probe connections ([23116a4](https://github.com/lexo-ch/laravel-health-endpoints/commit/23116a48292360d85d3e0d3d9bf46bef38960e57))
* harden health check generator and command tests ([aa4a6b5](https://github.com/lexo-ch/laravel-health-endpoints/commit/aa4a6b5edc61d929b0e7858cc85479526b7b769b))
* harden message sanitizer and targeted check edge cases ([5f8126f](https://github.com/lexo-ch/laravel-health-endpoints/commit/5f8126fbd09631420af9c07a6ba61d30bbdf42ec))
* harden runner against throwing targets and corrupt cache data ([f294e89](https://github.com/lexo-ch/laravel-health-endpoints/commit/f294e89afe3e1523810a04d8612f22ef6786ab11))
* harden settings, throttling, timeouts and JSON output from final review ([eadb53c](https://github.com/lexo-ch/laravel-health-endpoints/commit/eadb53c9b4fa374f237f10617dc35c0a7e227115))
* keep reports encodable, read in-memory migrations, warn on unknown stores ([a31e2a1](https://github.com/lexo-ch/laravel-health-endpoints/commit/a31e2a175c5033d8e9a06ce94e30ca81ea370709))
* tighten registry name and path validation and expand tests ([f80e306](https://github.com/lexo-ch/laravel-health-endpoints/commit/f80e306fafd30aaef5d70a6a8d1bf6b0788d7ed9))


### Features

* add artisan commands and generator stubs ([ed85950](https://github.com/lexo-ch/laravel-health-endpoints/commit/ed859505b3582679ea17439125b164dd6e5e8b79))
* add cache and storage checks ([f5f3c83](https://github.com/lexo-ch/laravel-health-endpoints/commit/f5f3c8383207fc4b33323df769c5562d48babe49))
* add check registry, discovery and facade ([b857fdc](https://github.com/lexo-ch/laravel-health-endpoints/commit/b857fdcff06d86968c2c7a76155f1aaaa7fb0231))
* add core result types, settings and message sanitizer ([fe1d595](https://github.com/lexo-ch/laravel-health-endpoints/commit/fe1d5954848bd83e3e744bfc056975c9844985fe))
* add database and redis checks ([19e9bca](https://github.com/lexo-ch/laravel-health-endpoints/commit/19e9bca1796721b12fa0e1d0f7e07af68a6d2927))
* add migrations and debug-mode checks ([83c3849](https://github.com/lexo-ch/laravel-health-endpoints/commit/83c38499ef908936782178218d70d5f8d3604ab7))
* add runner, reports and state-transition logging ([ca3cce6](https://github.com/lexo-ch/laravel-health-endpoints/commit/ca3cce6e636505a647f169058f202a7af4ccda41))
* add scheduler and queue heartbeat checks ([7013fe2](https://github.com/lexo-ch/laravel-health-endpoints/commit/7013fe217e3ad1d0af155627ad151a3b0c60c424))
* add secured health endpoints ([07ba9b0](https://github.com/lexo-ch/laravel-health-endpoints/commit/07ba9b002554a4bd379b3ac47affc36b05209a68))
* add short-lived result cache ([fa091cc](https://github.com/lexo-ch/laravel-health-endpoints/commit/fa091cc9be11216163873807e8adf60219b9abf6))
* inject frontend service markers ([b4aa6bb](https://github.com/lexo-ch/laravel-health-endpoints/commit/b4aa6bb1a21c135a820390dc5942232734892a19))
* let token holders request advanced output per request ([d7dc409](https://github.com/lexo-ch/laravel-health-endpoints/commit/d7dc4098a0345f2109615c0f25aaf9b5cd70d04e))


### BREAKING CHANGES

* ping no longer includes "version", and
LHE_DEBUG_FORBIDDEN_ENVIRONMENTS defaults to production,staging.
* responses contain only statuses unless LHE_OUTPUT=advanced.
LHE_SHOW_VERSION is removed; the ping version is shown with advanced output.
* 4xx error bodies use "status":"error" instead of "fail".
* the app block (name, environment, version) is removed from
all responses, ping has no timestamp, and LHE_SHOW_APP_INFO is now
LHE_SHOW_VERSION.
* LHE_APP_VERSION and LHE_DETAILS are no longer supported.
Unprotected endpoints now expose messages, versions and target names.

# Changelog

All notable changes to this project are documented in this file. It is generated automatically by [semantic-release](https://github.com/semantic-release/semantic-release) from [Conventional Commits](https://www.conventionalcommits.org/).
