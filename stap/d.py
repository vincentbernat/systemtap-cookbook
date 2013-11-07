"""Useful decorators for stap functions."""

import platform
import re
import functools
import logging
logger = logging.getLogger(__name__)

def warn(what):
    """Emit a warning about a specific function."""
    def w(fn):
        @functools.wraps(fn)
        def wrapper(*args, **kwargs):
            logger.warn("command `%s` has been marked as %s" % (
                fn.__name__, what))
            return fn(*args, **kwargs)
        d = wrapper.__doc__.split("\n")
        d[0] = "[%s] %s" % (what, d[0])
        wrapper.__doc__ = "\n".join(d)
        return wrapper
    return w

def arg(*args, **kwargs):
    """Add the provided argument to the parser."""
    def w(fn):
        if not hasattr(fn, "stap_args"):
            fn.stap_args = []
        fn.stap_args.append((args, kwargs))
        return fn
    return w

def enable(fn):
    """Enable the function as a valid subcommand."""
    fn.stap_enabled = True
    return fn

def linux(versions):
    """Emit a warning if running on an untested Linux version."""
    if not isinstance(versions, (list, tuple)):
        versions = [ versions ]
    def w(fn):
        @functools.wraps(fn)
        def wrapper(*args, **kwargs):
            current = platform.release()
            mo = re.match(r"((?:[0-9]+\.?)+)", current)
            if mo:
                current = mo.group(1).split(".")
            for version in versions:
                vv = version.split(".")
                if vv == current[:len(vv)]:
                    break
            else:
                logger.warn("command `%s` has not been tested with Linux %s" % (
                    fn.__name__, ".".join(current)))
            return fn(*args, **kwargs)
        return wrapper
    return w
