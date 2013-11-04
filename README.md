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

Previous kernels are missing important symbols.

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
