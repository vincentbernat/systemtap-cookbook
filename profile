#!/usr/bin/env python
# -*- coding: utf-8 -*-

"""Generic profiling tools
============================

This script will handle profiling in a generic way using kernel and
userspace backtraces.

"""

import sys
import os
import stap
import jinja2


@stap.d.enable
@stap.d.linux("3.11")
@stap.d.arg_pid
@stap.d.arg("--time", "-t", default=10, metavar="S", type=int,
            help="sample during S seconds")
@stap.d.arg("--limit", "-l", default=100, metavar="N", type=int,
            help="only display the N most frequent backtraces")
@stap.d.arg("--kernel", action="store_true",
            help="Sample in kernel-space")
@stap.d.arg("--user", action="store_true",
            help="Sample in user-space")
def backtrace(options):
    """Backtrace profiling.

    Sample backtraces and display the most frequent ones. By default,
    only kernel is sampled. When requesting user backtraces, a PID
    should be specified, otherwise, the backtraces will be mangled.

    """
    if not options.kernel and not options.user:
        options.kernel = True
    probe = jinja2.Template(ur"""
global backtraces%;
global quit;

probe timer.profile {
    if (!{{ options.condition }}) next;
    if (!quit) {
      backtraces[{{ backtraces }}] <<< 1;
    } else {
      foreach ([sys, usr] in backtraces- limit {{ options.limit }}) {
{%- if options.kernel %}
        print_stack(sys);
{%- endif %}
{%- if options.user %}
        print_ustack(usr);
{%- endif %}
        ansi_set_color2(30, 46);
        printf(" ♦ Number of occurrences: %-6d  \n", @count(backtraces[sys, usr]));
        ansi_reset_color();
      }
      exit();
    }
}

probe timer.s({{ options.time }}) {
    printf("Quitting...\n");
    quit = 1;
}
""")
    if options.kernel and options.user:
        backtraces = "backtrace(), ubacktrace()"
    elif options.kernel:
        backtraces = "backtrace(), 1"
    else:
        backtraces = "1, ubacktrace()"
    probe = probe.render(options=options,
                         backtraces=backtraces).encode("utf-8")
    args = options.kernel and ["--all-modules"] or []
    stap.execute(probe, options, *args)


stap.run(sys.modules[__name__])
