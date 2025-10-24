# GitHub Receipts

This is the source code that powers my GitHub issues receipt printer. If you'd like to learn more about what inspired this and how I put everything together, check out the full article and tutorial on it [here](https://aschmelyun.com/i-built-a-receipt-printer-for-github-issues).

[![A Twitter screenshot showing the printer](assets/twitter-embed-sm.jpg)](https://twitter.com/aschmelyun/status/1506960015063625733)

Udev rule:
`KERNEL=="lp[0-9]*", SUBSYSTEMS=="usb", ATTRS{idVendor}=="04b8", ATTRS{idProduct}=="0e15", MODE:="0666"`
