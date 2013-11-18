# -*- coding: utf-8 -*-

"""PHP helpers"""

import ctypes
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
        __max_depth = 16;
        return phpstack_n(__max_depth);
}

function phpstack_full:string() {
        __max_depth = 16;
        return phpstack_full_n(__max_depth);
}

function __php_functionname:string(t:long) {
        if (@cast(t, "zend_execute_data",
                     "{{ php }}")->function_state->function) {
          __name = user_string2(@cast(t, "zend_execute_data",
                        "{{ php }}")->function_state->function->common->function_name, "(anonymous)");
          if (@cast(t, "zend_execute_data",
                       "{{ php }}")->function_state->function->common->scope) {
            return sprintf("%s::%s",
                           user_string2(@cast(t, "zend_execute_data",
                                   "{{ php }}")->function_state->function->common->scope->name, "(unknown)"),
                           __name);
          }
          return __name;
        }
        return "???";
}

function __php_decode:string(z:long) {
        __type = @cast(z, "zval", "{{ php }}")->type;
        if (__type == 0) {
          __arg = "NULL";
        } else if (__type == 1) {
          __arg = sprintf("%d", @cast(z, "zval", "{{ php }}")->value->lval);
        } else if (__type == 2) {
          __arg = "<float>";
        } else if (__type == 3) {
          if (@cast(z, "zval", "{{ php }}")->value->lval) {
            __arg = "true";
          } else {
            __arg = "false";
          }
        } else if (__type == 4) {
          __arg = sprintf("<array(%d)>",
                          @cast(z, "zval", "{{ php }}")->value->ht->nNumOfElements);
        } else if (__type == 5) {
          __arg = sprintf("<object[%p]>", z);
        } else if (__type == 6) {
          __arg = user_string_n_quoted(@cast(z, "zval", "{{ php }}")->value->str->val,
                                       @cast(z, "zval", "{{ php }}")->value->str->len);
        } else {
          __arg = sprintf("<unknown(%d)>", __type);
        }
        return __arg;
}
function __php_decode_safe:string(z:long) {
        try {
          return __php_decode(z);
        } catch {
          return "<unavailable>";
        }
}

function __php_functionargs:string(t:long) {
        __void = {{ pointer }};
        __nb = user_int(@cast(t, "zend_execute_data",
                        "{{ php }}")->function_state->arguments);
        while (__nb > 0) {
          __zvalue = @cast(t, "zend_execute_data",
                            "{{ php }}")->function_state->arguments;
          __arg = __php_decode_safe(&@cast(user_long(__zvalue-__nb*__void), "zval", "{{ php }}"));
          __result = sprintf("%s%s%s", __result, (__result != "")?",":"", __arg);
          __nb--;
        }
        return __result;
}

function __php_location:string(t:long) {
        if (!@cast(t, "zend_execute_data", "{{ php }}")->op_array)
          return "(???)";
        return sprintf("%s:%d",
           user_string2(@cast(t, "zend_execute_data", "{{ php }}")->op_array->filename, "???"),
           @cast(t, "zend_execute_data", "{{ php }}")->opline->lineno);
}

function __php_function:string(t:long, full:long) {
        __name = __php_functionname(t);
        if (full) __args = __php_functionargs(t);
        __location = __php_location(t);
        return sprintf("%s(%s) %s", __name, __args, __location);
}

function __phpstack_n:string(max_depth:long, full:long) {
        try {
          __t = @var("executor_globals", "{{ php }}")->current_execute_data;
          while (__t && __depth < max_depth) {
            __result = sprintf("%s\n%s", __result, __php_function(__t, full));
            __depth++;
            __t = @cast(__t, "zend_execute_data", "{{ php }}")->prev_execute_data;
          }
          if (__result == "") return "(empty)";
          return __result;
        } catch {
          return "(unavailable)";
        }
}

function phpstack_full_n:string(max_depth:long) {
        return __phpstack_n(max_depth, 1);
}
function phpstack_n:string(max_depth:long) {
        return __phpstack_n(max_depth, 0);
}
""")
        return code.render(php=self.interpreter,
                           pointer=ctypes.sizeof(ctypes.c_void_p))

    def display(self, depth=16):
        """Display PHP backtrace at th current point."""
        return "print(phpstack_n({}))".format(depth);
