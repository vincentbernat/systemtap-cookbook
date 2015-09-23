systemtap cookbook
==================

Various scripts using systemtap for analysis and diagnostics. This is
inspired by [nginx-systemtap-toolkit][1] but scripts are written in
Python instead of Perl.

[1]: https://github.com/agentzh/nginx-systemtap-toolkit

Prerequisites
-------------

You need at least systemtap 2.3. Maybe it works with less recent
version but I did not test them. You also need Python 2.7. For most
scripts, you should ensure that the appropriate DWARF debuginfo have
been installed. For Debian, this means to either install `-dbg`
package or install from source without stripping debug symbols. For
Ubuntu, you can use [`dbgsym` packages][2].

[2]: https://wiki.ubuntu.com/DebuggingProgramCrash#Debug_Symbol_Packages

You need at least Linux kernel 3.5 with uprobes API for userspace
tracing. A reasonable setup for Ubuntu Precise is the following one:

    $ apt-get install linux-image-3.11.0-13-generic{,-dbgsym}
    $ apt-get install linux-headers-3.11.0-13-generic
    $ apt-get install make gcc

Previous kernels are missing important symbols. Ubuntu Precise has a
buggy GCC which is not able to handle kernels using `-mfentry`
flag. You can workaround this by setting `PR15123_ASSUME_MFENTRY`
environment variable to 1 with systemtap 2.4.

Permissions
-----------

Running systemtap-based tools requires special user permissions. To
prevent running these tools with the root account, you can add your
own (non-root) account name to the `stapusr` and `staprun` user
groups. But it is usually easier to just use `sudo`.

Tools
-----

Each script comes with appropriate help. Just run it with `--help` to
get comprehensive help with examples.

Some tools are emitting warnings:

 - `untested`: not really tested
 - `buggy`: can crash or produce no results

License
-------

 > Permission to use, copy, modify, and/or distribute this software for any
 > purpose with or without fee is hereby granted, provided that the above
 > copyright notice and this permission notice appear in all copies.
 >
 > THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 > WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 > MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 > ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 > WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 > ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 > OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
