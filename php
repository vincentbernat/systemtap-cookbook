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
@stap.d.arg("--slow", action="store_true",
            help="log slowest requests")
@stap.d.arg("--function", default="", type=str, metavar="FN",
            help="profile FN instead of the whole request")
def profile(options):
    """Profile PHP requests.

    Return distributions of response time for PHP requests.

    This probe relies on PHP Dtrace support. Be sure that PHP was
    compiled with :option:`--enable-dtrace` for this probe to work
    correctly.

    """
    probe = jinja2.Template(ur"""
global start%, intervals;
{%- if options.slow %}
global slow%;
{%- endif %}
{%- if options.function %}
global request%;
{%- endif %}

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}") {
{%- if options.function %}
        request[pid()] = sprintf("%5s %s", user_string($arg3), user_string($arg2));
{%- else %}
        start[pid()] = gettimeofday_ms();
{%- endif %}
    }
}

{% if options.function %}
function fn_name:string(name:long, class:long) {
    try {
      fn = sprintf("%s::%s",
                   user_string(class),
                   user_string(name));
    } catch {
      fn = "";
    }
    return fn;
}

probe process("{{ options.php }}").provider("php").mark("function__entry") {
    if (request[pid()] != "") {
      fn = fn_name($arg1, $arg4);
      if (fn == "{{ options.function }}")
        start[pid()] = gettimeofday_ms();
    }
}

probe process("{{ options.php }}").provider("php").mark("function__return") {
    if (start[pid()] && request[pid()] != "") {
      fn = fn_name($arg1, $arg4);
      if (fn == "{{ options.function }}")
        record(request[pid()]);
    }
}
{% endif %}

function record(uri:string) {
    t = gettimeofday_ms();
    old_t = start[pid()];
    if (old_t > 1 && t > 1) {
        intervals <<< t - old_t;
{%- if options.slow %}
        // We may miss some values...
        slow[t - old_t] = uri
{%- endif %}
    }
    delete start[pid()];
}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
{%- if options.function %}
    delete request[pid()];
{%- else %}
    record(sprintf("%5s %s", user_string($arg3), user_string($arg2)));
{%- endif %}
}

probe timer.ms({{ options.interval }}) {
    ansi_clear_screen();
{%- if options.log %}
    print(@hist_log(intervals));
{%- else %}
    print(@hist_linear(intervals, 0, {{ options.step * 20 }}, {{ options.step }}));
{%- endif %}
    printf(" — URI prefix: %s\n", "{{ options.uri }}");
{%- if options.function %}
    printf(" — Function: %s\n", "{{ options.function }}");
{%- endif %}
    printf(" — min:%dms avg:%dms max:%dms count:%d\n",
                     @min(intervals), @avg(intervals),
                     @max(intervals), @count(intervals));
{% if options.slow %}
    printf(" — slowest requests:\n");
    foreach (t- in slow limit 10) {
      printf("   %6dms: %s\n", t, slow[t]);
    }
    delete slow;
{% endif %}
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)


stap.run(sys.modules[__name__])
