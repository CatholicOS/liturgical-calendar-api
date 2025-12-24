Run coderabbit --plain, let it run as long as it needs (run it in the background).

Evaluate the fixes and considerations. Fix major issues, critical issues, and only fix the nits if they make sense even if they seem out of scope.

Once those changes are implemented, run CodeRabbit CLI one more time to make sure
we addressed all the critical issues and didn't introduce any additional bugs.

Only run the loop up to four times. If on the fourth run you don't find any critical issues,
ignore the nits and you're complete. Give me a summary of everything that was
completed and why.
