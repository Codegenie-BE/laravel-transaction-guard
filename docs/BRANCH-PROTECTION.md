# Main branch policy

`main` is the release branch. Changes should enter through a pull request after the `Tests` workflow succeeds.

Required release gates:

- all PHP/Laravel quality-matrix jobs;
- `Lowest supported dependencies`;
- `Coverage / PHP 8.5 / Laravel 13`;
- no force-pushes or branch deletion;
- Codegenie ownership review for repository changes.

The repository workflows are designed so release tagging only happens after a successful `Tests` run on `main`; tag publication is isolated from cancel-in-progress test concurrency.
