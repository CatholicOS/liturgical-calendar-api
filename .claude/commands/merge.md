## Steps to Merge and Clean Up

1. Merge the PR using `gh pr merge <pr-number> --merge --delete-branch`, handling any conflicts if needed.
2. Confirm the merge was successful by checking the PR status: `gh pr view <pr-number>`.
3. Checkout the target branch (the branch the PR was merged into, e.g., `master` or `development`).
4. Pull the changes: `git pull origin <target-branch>`.
5. Delete the local branch if it wasn't already deleted: `git branch -d <branch-name>`.
