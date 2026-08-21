# Contributing

Contributions are welcome.

1. Keep changes narrowly scoped and Laravel-native.
2. Add a regression scenario for every analyzer behavior change.
3. Run `composer check:all` and `composer test:coverage`.
4. Do not reduce safety to silence a false positive; improve the proof or add a narrowly scoped suppression mechanism.
5. New rules need a rule ID, severity, remediation text, documentation and safe/unsafe tests.

Bug reports should include a minimal PHP snippet that reproduces the finding or missed finding, expected behavior, Laravel version and queue configuration when relevant.
