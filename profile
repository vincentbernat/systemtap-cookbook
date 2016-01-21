#!/usr/bin/env python
# -*- coding: utf-8 -*-

"""Generic profiling tools
============================

This script will handle profiling in a generic way using kernel and
userspace backtraces.

"""

import sys
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

    It is possible to generate a flamegraph using Brendan Gregg's
    scripts available here:

       https://github.com/brendangregg/FlameGraph
    """
    if not options.kernel and not options.user:
        options.kernel = True
    probe = jinja2.Template(ur"""
global backtraces%;
global quit;

probe timer.profile {
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


@stap.d.enable
@stap.d.linux("4.3")
@stap.d.warn("buggy")
@stap.d.arg_pid
@stap.d.arg("--time", "-t", default=10, metavar="S", type=int,
            help="sample during S seconds")
@stap.d.arg("--limit", "-l", default=100, metavar="N", type=int,
            help="only display the N most frequent backtraces")
@stap.d.arg("--kernel", action="store_true",
            help="Sample in kernel-space")
@stap.d.arg("--user", action="store_true",
            help="Sample in user-space")
def offcpu(options):
    """Off-CPU backtrace profiling.

    Sample backtraces when going off CPU and display the most frequent
    ones. By default, only kernel is sampled. When requesting user
    backtraces, a PID should be specified, otherwise, the backtraces
    will be mangled.

    It is possible to generate a flamegraph using Brendan Gregg's
    scripts available here:

       https://github.com/brendangregg/FlameGraph

    """
    if not options.kernel and not options.user:
        options.kernel = True
    probe = jinja2.Template(ur"""
global backtraces%;
global start_time%;
global quit;

probe scheduler.cpu_off {
    if (!quit) {
      if ({{options.condition}}) {
        start_time[tid()] = gettimeofday_us();
      }
    } else {
      foreach ([sys, usr] in backtraces- limit {{ options.limit }}) {
{%- if options.kernel %}
        print_stack(sys);
{%- endif %}
{%- if options.user %}
        print_ustack(usr);
{%- endif %}
        ansi_set_color2(30, 46);
        printf(" ♦ Occurrences:  %-6d  \n", @count(backtraces[sys, usr]));
        printf(" ♦ Elapsed time: %-6d  \n", @sum(backtraces[sys, usr]));
        ansi_reset_color();
      }
      exit();
    }
}

probe scheduler.cpu_on {
    if (({{options.condition}}) && !quit) {
      t = tid();
      begin = start_time[t];
      if (begin > 0) {
        elapsed = gettimeofday_us() - begin;
        backtraces[{{ backtraces }}] <<< elapsed;
      }
      delete start_time[t];
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


@stap.d.enable
@stap.d.linux("4.2")
@stap.d.arg("--time", "-t", default=10, metavar="S", type=int,
            help="sample during S seconds")
@stap.d.arg("--max-cpus", default=64, metavar="CPU", type=int,
            help="maximum number of CPU")
@stap.d.arg("probe", metavar="PROBE",
            help="probe point to sample")
def histogram(options):
    """Display an histogram of execution times for the provided probe point.

    A probe point can be anything understood by Systemtap. For example:

     - kernel.function("net_rx_action")
     - process("haproxy").function("frontend_accept")
    """
    # Stolen from:
    #  https://github.com/majek/dump/blob/master/system-tap/histogram-kernel.stp
    probe = jinja2.Template(ur"""
global trace[{{ options.max_cpus }}];
global etime[{{ options.max_cpus }}];
global intervals;

probe {{ options.probe }}.call {
    trace[cpu()]++;
    if (trace[cpu()] == 1) {
        etime[cpu()] = gettimeofday_ns();
    }
}

probe {{ options.probe }}.return {
    trace[cpu()]--;
    if (trace[cpu()] <= 0) {
        t1_ns = etime[cpu()];
        trace[cpu()] = 0;
        etime[cpu()] = 0;
        if (t1_ns == 0) {
            printf("Cpu %d was already in that function?\n", cpu());
        } else {
            intervals <<< (gettimeofday_ns() - t1_ns)/1000;
        }
    }
}

probe end {
    printf("Duration min:%dus avg:%dus max:%dus count:%d\n",
           @min(intervals), @avg(intervals), @max(intervals),
           @count(intervals))
    printf("Duration (us):\n");
    print(@hist_log(intervals));
    printf("\n");
}
probe timer.sec( {{options.time }}) {
    exit();
}
""")
    probe = probe.render(options=options).encode("utf-8")
    args = "--all-modules"
    stap.execute(probe, options, *args)


stap.run(sys.modules[__name__])
