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
        code = jinja2.Template(ur"""
function phpstack:string() {
        max_depth = 16;
        return phpstack_n(max_depth);
}

function __php_functionname:string(t:long) {
        try {
          name = @cast(t, "zend_execute_data",
                        "{{ php }}")->function_state->function->common->function_name;
          fname = user_string(name);
        } catch {
          fname = "???";
        }
        try {
          scope = @cast(t, "zend_execute_data",
                           "{{ php }}")->function_state->function->common->scope->name;
          return sprintf("%s::%s", user_string(scope), fname);
        } catch {}
        return fname;
}

function __php_functionargs:string(t:long) {
        nb = user_int(@cast(t, "zend_execute_data",
                        "{{ php }}")->function_state->arguments);
        return sprintf("%d", nb);
}

function __php_function:string(t:long) {
        name = __php_functionname(t);
        return sprintf("%s()", name);
}

function phpstack_n:string(max_depth:long) {
        t = @var("executor_globals", "{{ php }}")->current_execute_data;
        while (t && depth < max_depth) {
          result = sprintf("%s\n%s", result, __php_function(t));
          depth++;
          t = @cast(t, "zend_execute_data", "{{ php }}")->prev_execute_data;
        }
        return result;
}
""")
        return code.render(php=self.interpreter)

    def display(self, depth=16):
        """Display PHP backtrace at th current point."""
        return "print(phpstack_n({}))".format(depth);
