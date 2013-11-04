# -*- encoding: utf-8 -*-

"""PHP helpers"""

import subprocess
import jinja2

def extension_dir():
    """Get PHP extension directory."""
    extension_dir = subprocess.check_output(["php", "-r",
                                             'echo ini_get("extension_dir");'])
    return extension_dir


class backtrace(object):
    """Helper functions to get PHP backtraces"""

    def __init__(self, interpreter):
        self.interpreter = interpreter

    def init(self):
        code = jinja2.Template(u"""
    global __phptrace%;
    global __phpdepth%;

    function php_log(mark, arg1, arg2, arg3, arg4) {
        try {
          fn = sprintf(" %s::%s() [in %s:%d]",
                       user_string(arg4),
                       user_string(arg1),
                       user_string(arg2),
                       arg3);
        } catch {
          fn = "";
        }
        if (fn != "") {
          d = __phpdepth[pid()];
          if (mark == "←") {
            __phpdepth[pid()] = d-1;
            delete __phptrace[pid(), d-1];
          } else {
            __phptrace[pid(), d] = fn;
            __phpdepth[pid()] = d+1;
          }
        }
    }

    probe process("{{ php }}").provider("php").mark("request__startup") {
        __phpdepth[pid()] = 0;
    }
    probe process("{{ php }}").provider("php").mark("function__entry") {
        php_log("→", $arg1, $arg2, $arg3, $arg4);
    }
    probe process("{{ php }}").provider("php").mark("function__return") {
        php_log("←", $arg1, $arg2, $arg3, $arg4);
    }
""")
        return code.render(php=self.interpreter)

    def display(self):
        """Display PHP backtrace for current PID"""
        return r"""
foreach ([p, d+] in __phptrace) {
    if (p != pid()) continue;
    printf("%s\n", __phptrace[p,d]);
}
"""
