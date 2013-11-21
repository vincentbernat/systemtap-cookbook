#!/usr/bin/env python
# -*- coding: utf-8 -*-

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
def time(options):
    """Distributions of response time for PHP requests.
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
    if (@count(intervals) == 0) {
      printf("No data yet...\n");
      next;
    }
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
global mem, pagesize, track;
{%- if not options.absolute %}
global memusage;
{%- endif %}
{%- if options.big %}
global big%;
{%- endif %}

probe begin {
    pagesize = {{ memfunc }}();
}

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}") {
{%- if not options.absolute %}
      memusage[pid()] = {{ memfunc }}() * pagesize;
{%- endif %}
      track[pid()] = 1;
    }
}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    if (!track[pid()]) next;
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
    delete track[pid()];
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


@stap.d.enable
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--interval", default=1000, type=int,
            help="delay between screen updates in milliseconds")
@stap.d.arg("--log", action="store_true",
            help="display a logarithmic histogram")
@stap.d.arg("--step", type=int, default=2000000, metavar="BYTES",
            help="each bucket represents BYTES bytes")
@stap.d.arg("--big", action="store_true",
            help="log bigger memory users")
def peak(options):
    """Display memory peak usage for each requests.

    This examines PHP heap. See: https://wiki.php.net/internals/zend_mm.

    """
    probe = jinja2.Template(ur"""
global mem;
{%- if options.big %}
global big%;
{%- endif %}

{#- We don't use request__shutdown probe: it is too late #}
probe process("{{ options.php }}").function("php_request_shutdown") {
    uri = user_string2(@var("sapi_globals", "{{ options.php }}")->request_info->request_uri, "(unknown)");
    if (substr(uri, 0, {{ options.uri|length() }}) != "{{ options.uri }}") next;
    peak = @var("alloc_globals", "{{ php }}")->mm_heap->real_peak;
    mem <<< peak;
{%- if options.big %}
    big[peak] = uri;
{%- endif %}
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
{% endif %}
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)

@stap.d.enable
@stap.d.warn("buggy")
@stap.d.warn("untested")
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--time", type=int, default=10, metavar="S",
            help="how much time we should run")
@stap.d.arg("--depth", type=int, default=1, metavar="D",
            help="PHP backtrace depth to consider")
def malloc(options):
    """Display most frequent memory allocation backtraces.

    """
    probe = jinja2.Template(ur"""
{{ backtrace.init() }}

global allocs%;

{%- if options.uri %}
global enabled;

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}")
      enabled[pid()] = 1;
}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}")
      delete enabled[pid()];
}
{%- endif %}

probe process("{{ options.php }}").function("_zend_mm_realloc_int").return,
      process("{{ options.php }}").function("_zend_mm_alloc_int").return {
{%- if options.uri %}
    if (!enabled[pid()]) next;
{%- endif %}
    if ($return != 0)
      allocs[phpstack_n({{ options.depth }})] <<< $size;
}

probe timer.s({{ options.time }}) {
    foreach (stack in allocs- limit 10) {
      ansi_set_color2(30, 46);
      printf(" Allocated %s (%d times): \n",
             bytes_to_string(@sum(allocs[stack])),
             @count(allocs[stack]));
      ansi_reset_color();
      printf("%s\n\n", stack);
    }
    exit();
}

""")
    probe = probe.render(options=options,
                         backtrace=stap.h.php.Backtrace(options.php)).encode("utf-8")
    stap.execute(probe, options)


@stap.d.enable
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--interval", default=1000, type=int,
            help="delay between screen updates in milliseconds")
@stap.d.arg("--busy", action="store_true",
            help="log busiest requests")
@stap.d.arg("--function", default="", type=str, metavar="FN",
            help="profile FN instead of the whole request")
def cpu(options):
    """Display CPU usage

    Return distributions of CPU usage for PHP requests.
    """
    probe = jinja2.Template(ur"""
global start%, use%, usage;
{%- if options.busy %}
global busy%;
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
        use[pid()] = task_stime() + task_utime();
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
      if (fn == "{{ options.function }}") {
        start[pid()] = gettimeofday_ms();
        use[pid()] = task_stime() + task_utime();
      }
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
    u = task_stime() + task_utime();
    t = gettimeofday_ms();
    old_u = use[pid()];
    old_t = start[pid()];
    if (old_t && t && old_u && u && t != old_t) {
        percent = cputime_to_msecs(u - old_u) * 100 / (t - old_t);
        if (percent > 100) percent = 100;
        usage <<< percent;
{%- if options.busy %}
        // We may miss some values...
        busy[percent] = uri;
{%- endif %}
    }
    delete start[pid()];
    delete use[pid()];
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
    print(@hist_linear(usage, 0, 100, 10));
    printf(" — URI prefix: %s\n", "{{ options.uri }}");
{%- if options.function %}
    printf(" — Function: %s\n", "{{ options.function }}");
{%- endif %}
    printf(" — min:%d%% avg:%d%% max:%d%% count:%d\n",
                     @min(usage), @avg(usage),
                     @max(usage), @count(usage));
{% if options.busy %}
    printf(" — busyest requests:\n");
    foreach (t- in busy limit 10) {
      printf("   %6d%%: %s\n", t, busy[t]);
    }
    delete busy;
{% endif %}
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)


@stap.d.enable
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--interval", default=5000, type=int,
            help="delay between screen updates in milliseconds")
@stap.d.arg("--step", type=int, default=1, metavar="N",
            help="each bucket represents N calls")
@stap.d.arg("--buckets", type=int, default=20, metavar="N",
            help="how many buckets to display")
@stap.d.arg("--disable-hist", dest="hist",
            action="store_false",
            help="disable display of distribution histogram")
@stap.d.arg("functions", nargs="+",
            type=str, metavar="FN",
            help="functions to count")
def count(options):
    """Distributions of PHP function calls per requests.

    """
    probe = jinja2.Template(ur"""
global fns;
global count%;
global countfn;
global acountfn;

probe begin {
    {%- for item in options.functions %}
    fns["{{ item }}"] = 1;
    {%- endfor %}
}

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}") {
        count[pid(), ""] = 1;
        foreach (fn in fns) count[pid(), fn] = 0;
    }
}

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
    if (count[pid(), ""] != 1) next;
    fn = fn_name($arg1, $arg4);
    if ([fn] in fns) {
        count[pid(), fn]++
    }
}

probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    if (count[pid(), ""] != 1) next;
    foreach ([p, fn] in count) {
      if (p != pid()) continue;
      if (fn == "") continue;
      countfn[fn] += count[p, fn];
    }
    foreach (fn in countfn) {
      acountfn[fn] <<< countfn[fn];
    }
    delete countfn;
    delete count[pid(), ""];
}

probe timer.ms({{ options.interval }}) {
    ansi_clear_screen();
    foreach (fn in acountfn) {
      ansi_set_color2(30, 46);
      printf(" — Function %-30s: \n", fn);
      ansi_reset_color();
{%- if options.hist %}
      print(@hist_linear(acountfn[fn], 0, {{ options.step * options.buckets }}, {{ options.step }}));
{%- endif %}
      printf(" min:%d avg:%d max:%d count:%d\n\n",
                     @min(acountfn[fn]), @avg(acountfn[fn]),
                     @max(acountfn[fn]), @count(acountfn[fn]));
    }
    delete acountfn;
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)


@stap.d.enable
@stap.d.arg("--php", type=str, default="/usr/lib/apache2/modules/libphp5.so",
            help="path to PHP process or module")
@stap.d.arg("--uri", type=str, default="/", metavar="PREFIX",
            help="restrict the profiling to URI prefixed by PREFIX")
@stap.d.arg("--limit", type=int, default=10, metavar="N",
            help="show the most N frequent backtraces")
@stap.d.arg("--depth", type=int, default=10, metavar="N",
            help="limit the backtraces to N function calls")
@stap.d.arg("--time", "-t", default=10, metavar="S", type=int,
            help="sample during S seconds")
@stap.d.arg("--frequency", type=int, default=97,
            help="sample frequency")
@stap.d.arg("--c", action="store_true",
            help="also display C backtrace")
def profile(options):
    """Sample backtraces to find the most used ones.
    """
    probe = jinja2.Template(ur"""
{{ backtrace.init() }}

global cantrace;
global traces%;

probe process("{{ options.php }}").provider("php").mark("request__startup") {
    if (user_string_n($arg2, {{ options.uri|length() }}) == "{{ options.uri }}")
        cantrace[pid()] = 1;
}


probe process("{{ options.php }}").provider("php").mark("request__shutdown") {
    delete cantrace[pid()];
}

probe timer.us({{ (1000000 / options.frequency)|int() }}) {
    if (!cantrace[pid()]) next;
{%- if options.c %}
    traces[phpstack_n({{ options.depth }}), sprint_ubacktrace()] <<< 1;
{%- else %}
    traces[phpstack_n({{ options.depth }}), 1] <<< 1;
{%- endif %}
}

probe timer.s({{ options.time }}) {
   foreach ([php, c] in traces- limit {{ options.limit }}) {
        printf("--- PHP backtrace ---\n");
        printf("%s\n", php);
{%- if options.c %}
        printf("---- C backtrace ----\n");
        printf("%s\n", c);
{%- endif %}
        ansi_set_color2(30, 46);
        printf(" ♦ Number of occurrences: %-6d  \n", @count(traces[php, c]));
        ansi_reset_color();
   }
   exit();
}
""")
    probe = probe.render(options=options,
                         backtrace=stap.h.php.Backtrace(options.php)).encode("utf-8")
    stap.execute(probe, options, "-DMAXSTRINGLEN={}".format(options.depth*200))


stap.run(sys.modules[__name__])
