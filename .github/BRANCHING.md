# Branch strategy

This repository currently develops two active version lines in parallel. The branch relationships below are intentional and should be preserved by future work.

## Current branch model

- `3.1` is the current stable development and maintenance line.
- `master` represents the current stable code line.
- Changes merged into `3.1` flow directly into `master`.
- While both version lines are active, every change merged into `3.1` must also be forward-synced into `3.2`.
- `3.2` is based on `3.1` and adds the upcoming `3.2.0` WooCommerce and fraud integration work.

In practical terms:

```text
3.1 -> master
3.1 -> 3.2
3.2 = 3.1 + 3.2.0 development
```

`3.2` must not drift behind `3.1`. Maintenance fixes and stable changes belong in `3.1` first when they apply to both lines, then move forward into `3.2`.

## Releases and tags

Branch synchronization is not a release operation.

- Do not create a Git tag or GitHub release merely because `3.1` is merged into `master` or forward-synced into `3.2`.
- The code may identify itself as `3.1.6` without a corresponding published `3.1.6` tag.
- Do not create or publish a `3.1.6` tag/release unless explicitly requested.
- The same rule applies to `3.2.0`: development on the `3.2` branch does not itself authorize tagging or publishing a release.

## Automated synchronization and conflicts

Synchronization from `3.1` is handled by GitHub Actions rather than recurring synchronization pull requests.

- A push to `3.1` triggers synchronization into both `master` and `3.2`.
- Each target is processed independently so a failure for one target does not cancel the other target.
- If a target already contains all commits from `3.1`, that target is left unchanged.
- When the merge is clean, the Action creates a normal merge commit and pushes it to the target branch.
- Never force-push, force-merge, silently discard changes, or automatically resolve conflicts.
- A merge conflict or a branch-protection rejection must make that target's Action job fail visibly and require explicit intervention.
- Manual `workflow_dispatch` remains available to retry synchronization after a problem has been resolved.

## Development direction

`3.1` remains the stable maintenance base while `3.2` develops the broader WooCommerce/fraud architecture. Features that belong specifically to the new 3.2 architecture should target `3.2`. Fixes or stable improvements that should also exist in the current stable code should normally target `3.1` first and then be forward-synced automatically.
