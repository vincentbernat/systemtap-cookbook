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


@stap.d.enable
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict requests to URI prefixed by PREFIX")
@stap.d.arg("--interval", default=500, type=int, metavar="MS",
            help="delay between screen updates in milliseconds")
@stap.d.arg("--limit", default=20, type=int, metavar="N",
            help="display the top N active requests")
def activereqs(options):
    """Display active PHP requests.

    Active PHP requests are displayed in order of execution length. A
    summary is also provided to show the number of active requests
    over the number of total requests.

    """
    probe = jinja2.Template(ur"""
global request%, since%, requests, actives;

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}") {
      request[pid()] = sprintf("%5s %s", user_string($arg3), user_string($arg2));
      since[pid()] = gettimeofday_ms();
      requests++; actives++;
    }
}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    if (since[pid()]) {
      delete request[pid()];
      delete since[pid()];
      actives--;
    }
}

probe timer.ms({{ options.interval }}) {
    t = gettimeofday_ms();
    ansi_clear_screen();
    foreach (p in since+ limit {{ options.limit }}) {
      r = request[p];
      if (strlen(r) > 60)
        r = sprintf("%s...%s", substr(r, 0, 45), substr(r, strlen(r) - 15, strlen(r)));
      printf("   %6dms: %s\n", t - since[p], r);
    }

    for (i = actives; i < {{ options.limit }}; i++) printf("\n");
    printf("\n");
    ansi_set_color2(30, 46);
    printf(" ♦ Active requests: %-6d  \n", actives);
    printf(" ♦  Total requests: %-6d  \n", requests);
    ansi_reset_color();

    requests = 0;
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)


@stap.d.enable
@stap.d.warn("buggy")
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--interval", default=1000, type=int,
            help="delay between screen updates in milliseconds")
@stap.d.arg("--log", action="store_true",
            help="display a logarithmic histogram")
@stap.d.arg("--step", type=int, default=500000, metavar="BYTES",
            help="each bucket represents BYTES bytes")
@stap.d.arg("--big", action="store_true",
            help="log bigger memory users")
@stap.d.arg("--absolute", action="store_true",
            help="log absolute memory usage")
@stap.d.arg("--memtype", choices=["data", "rss", "shr", "txt" "total"],
            default="total",
            help="memory type to watch for")
def memory(options):
    """Display memory usage of PHP requests.

    This is not reliable as it seems that memory is allocated
    early. The usage is therefore lower than it should be. You can use
    :option:`--absolute` to get absolute memory usage instead. This
    time, this overestimate the memory use.

    """
    probe = jinja2.Template(ur"""
global mem, pagesize;
{%- if not options.absolute %}
global memusage;
{%- endif %}
{%- if options.big %}
global big%;
{%- endif %}

probe begin {
    pagesize = {{ memfunc }}();
}

{%- if not options.absolute %}
probe process("{{ options.php }}").provider("php").mark("request__startup") {
    memusage[pid()] = {{ memfunc }}() * pagesize;
}
{%- endif %}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    m = proc_mem_size() * pagesize;
{%- if not options.absolute %}
    old_m = memusage[pid()];
    delete memusage[pid()];
    if (old_m && m && m - old_m > 0) {
{%- else %}
    old_m = 0;
    if (m) {
{%- endif %}
      mem <<< m - old_m;
{%- if options.big %}
      request = sprintf("%5s %s", user_string($arg3), user_string($arg2));
      big[m - old_m] = request;
{%- endif %}
    }
}

probe timer.ms({{ options.interval }}) {
    ansi_clear_screen();
{%- if options.log %}
    print(@hist_log(mem));
{%- else %}
    print(@hist_linear(mem, 0, {{ options.step * 20 }}, {{ options.step }}));
{%- endif %}
    printf(" — URI prefix: %s\n", "{{ options.uri }}");
    printf(" — min:%s avg:%s max:%s count:%d\n",
                     bytes_to_string(@min(mem)),
                     bytes_to_string(@avg(mem)),
                     bytes_to_string(@max(mem)),
                     @count(mem));
{% if options.big %}
    printf(" — biggest users:\n");
    foreach (t- in big limit 10) {
      printf("   %10s: %s\n", bytes_to_string(t), big[t]);
    }
    delete big;
{% endif %}
}
""")
    memfunc = "proc_mem_{}".format(
        dict(total="size").get(options.memtype, options.memtype))
    probe = probe.render(options=options,
                         memfunc=memfunc).encode("utf-8")
    stap.execute(probe, options)


stap.run(sys.modules[__name__])
