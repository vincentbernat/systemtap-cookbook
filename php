#!/usr/bin/env python
# -*- encoding: utf-8 -*-

"""PHP related tools
============================

This script will handle various instrumentation related to PHP interpreter.

"""

import sys
import os
import stap
import jinja2


@stap.d.enable
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--interval", default=1000, type=int,
            help="delay between screen updates in milliseconds")
@stap.d.arg("--log", action="store_true",
            help="display a logarithmic histogram")
@stap.d.arg("--step", type=int, default=10, metavar="MS",
            help="each bucket represents MS milliseconds")
def profile(options):
    """Profile PHP requests.

    Return distributions of response time for PHP requests.

    This probe relies on PHP Dtrace support. Be sure that PHP was
    compiled with :option:`--enable-dtrace` for this probe to work
    correctly.

    """
    probe = jinja2.Template(ur"""
global start%, intervals;

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}")
        start[pid()] = gettimeofday_ms();
}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    t = gettimeofday_ms();
    old_t = start[pid()];
    if (old_t) intervals <<< t - old_t;
    delete start[pid()];
}

probe timer.ms({{ options.interval }}) {
    ansi_clear_screen();
{%- if options.log %}
    print(@hist_log(intervals));
{%- else %}
    print(@hist_linear(intervals, 0, {{ options.step * 20 }}, {{ options.step }}));
{%- endif %}
    printf(" — URI prefix: %s\n", "{{ options.uri }}");
    printf(" — min:%dms avg:%dms max:%dms count:%d\n",
                     @min(intervals), @avg(intervals),
                     @max(intervals), @count(intervals));
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)


stap.run(sys.modules[__name__])
