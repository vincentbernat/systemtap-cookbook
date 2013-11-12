# -*- coding: utf-8 -*-

"""PHP helpers"""

import subprocess
import jinja2

def extension_dir():
    """Get PHP extension directory."""
    extension_dir = subprocess.check_output(["php", "-r",
                                             'echo ini_get("extension_dir");'])
    return extension_dir


class Backtrace(object):
    """Helper functions to get PHP backtraces.

    When a backtrace should be displayed, call :method:`display`. The
    backtrace will be displayed by reacting to function
    return. Therefore, displaying the backtrace can take some time
    (until the request is shutdown). To avoid to interlace several
    backtraces, only one backtrace can be displayed at a given time.

    """

    def __init__(self, interpreter):
        self.interpreter = interpreter

    def init(self):
        code = jinja2.Template(u"""
    global __phptraceenable;
    global __phptraceskip;

    probe process("{{ php }}").provider("php").mark("request__shutdown") {
        if (__phptraceenable != pid()) next;
        printf(" — End of backtrace for %s %s\\n",
           user_string($arg3), user_string($arg2));
        __phptraceenable = 0;
    }
    probe process("{{ php }}").provider("php").mark("function__entry") {
        if (__phptraceenable == pid())
          __phptraceskip++;
    }
    probe process("{{ php }}").provider("php").mark("function__return") {
        if (__phptraceenable != pid()) next;
        if (__phptraceskip > 0) {
          __phptraceskip--;
          next;
        }
        try {
          fn = sprintf(" %s::%s() [in %s:%d]",
                       user_string($arg4),
                       user_string($arg1),
                       user_string($arg2),
                       $arg3);
        } catch {
          fn = "";
        }
        if (fn != "") {
          printf("%s\\n", fn);
        }
    }
""")
        return code.render(php=self.interpreter)

    def display(self):
        """Enable PHP backtrace for current PID.

        The trace is displayed on standard output. It is not possible
        to display several traces at once, therefore, displaying a
        trace disable displaying trace for other processes.

        """
        return r"""
if (__phptraceenable == 0) {
  __phptraceenable = pid();
  __phptraceskip = 0;
}
"""

    def busy(self):
        """Tell if we are busy displaying a backtrace."""
        return "(__phptraceenable != 0)"
