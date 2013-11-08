#!/usr/bin/env python
# -*- encoding: utf-8 -*-

"""IO tools
============================

This script groups IO related tools

"""

import sys
import os
import stap
import jinja2


@stap.d.enable
@stap.d.linux("3.11")
@stap.d.arg("--limit", "-l", default=20, type=int, metavar="N",
             help="display N top processes")
def top(options):
    """iotop-like tool.

    Display top users of IO disks. Those are IO as seen from the VFS
    point of view.

    """
    probe = jinja2.Template(ur"""
global ioreads, iowrites, breads, bwrites, all;

probe vfs.read.return {
    breads[pid(),cmdline_str()] += bytes_read;
    ioreads[pid(),cmdline_str()] += 1;
}

probe vfs.write.return {
    bwrites[pid(),cmdline_str()] += bytes_written;
    iowrites[pid(),cmdline_str()] += 1;
}

function human_bytes:string(bytes:long) {
    return sprintf("%sB/s", bytes_to_string(bytes));
}
function human_iops:string(iops:long) {
    prefix = " ";
    if (iops > 10000000000) {
      prefix = "G";
      iops /= 10000000000;
    } else if (iops > 1000000) {
      iops /= 10000000;
      prefix = "M";
    } else if (iops > 10000) {
      iops /= 1000;
      prefix = "K";
    }
    return sprintf("%d%s/s", iops, prefix);
}
function average:string(bytes:long, iops:long) {
    if (iops == 0) return "-";
    return bytes_to_string(bytes/iops);
}

probe timer.s(1) {
    foreach ([t,s] in breads) {
      tbreads += breads[t,s];
      all[t,s] += breads[t,s];
    }
    foreach ([t,s] in bwrites) {
      tbwrites += bwrites[t,s];
      all[t,s] += bwrites[t,s];
    }
    foreach ([t,s] in ioreads) tioreads += ioreads[t,s];
    foreach ([t,s] in iowrites) tiowrites += iowrites[t,s];
    ansi_clear_screen();
    printf("Total read:  %10s / %10s (avg req size: %10s) \n",
       bytes_to_string(tbreads), human_iops(tioreads),
       average(tbreads, tioreads));
    printf("Total write: %10s / %10s (avg req size: %10s) \n",
       bytes_to_string(tbwrites), human_iops(tiowrites),
       average(tbwrites, tiowrites));
    ansi_set_color2(30, 46);
    printf("%5s  %10s %10s %8s %10s %10s %8s %-30s\n", "PID",
      "RBYTES/s", "READ/s", "rAVG",
      "WBYTES/s", "WRITE/s", "wAVG", "COMMAND");
    ansi_reset_color();
    foreach ([t,s] in all- limit {{ options.limit }}) {
      cmd = substr(s, 0, 30);
      printf("%5d  %10s %10s %8s %10s %10s %8s %s\n",
        t,
        human_bytes(breads[t,s]),
        human_iops(ioreads[t,s]),
        average(breads[t,s], ioreads[t,s]),
        human_bytes(bwrites[t,s]),
        human_iops(iowrites[t,s]),
        average(bwrites[t,s], iowrites[t,s]),
        cmd);
    }
    delete all;
    delete ioreads;
    delete iowrites;
    delete breads;
    delete bwrites;
}
""")
    probe = probe.render(options=options).encode("utf-8")
    stap.execute(probe, options)


stap.run(sys.modules[__name__])
