import sys
import os
import argparse
import inspect
import subprocess
import re

from stap import log
from stap import d
from stap import h

__all__ = [ "run", "execute", "d", "h" ]


def normalize_fn(name):
    return name.replace("_", "-")

def get_subcommands(module):
    """Extract list of subcommands in the given module."""
    return [obj
            for name, obj in inspect.getmembers(module)
            if inspect.isfunction(obj)
            and hasattr(obj, "stap_enabled")
            and obj.stap_enabled]

def get_options(module):
    """Return the command-line options.

    The provided module will be inspected for functions enabled with
    `stap.enable` and provide subcommands for each of them.
    """
    raw = argparse.RawDescriptionHelpFormatter
    parser = argparse.ArgumentParser(description=module.__doc__,
                                     formatter_class=raw)

    g = parser.add_mutually_exclusive_group()
    g.add_argument("--debug", "-d", action="store_true",
                   default=False,
                   help="enable debugging")
    g.add_argument("--silent", "-s", action="store_true",
                   default=False,
                   help="silent output")

    parser.add_argument("--stap-arg", "-a", metavar="ARG", type=str, nargs="+",
                        dest="stapargs",
                        help="pass an extra argument to the stap utility")
    parser.add_argument("--stap-no-overload", action="store_true",
                        dest="stapnooverload",
                        help="don't check for overload (dangerous)")
    parser.add_argument("--dump", "-D", action="store_true",
                        help="dump the systemtap script source")

    subparsers = parser.add_subparsers(help="subcommands", dest="command")
    for fn in get_subcommands(module):
        subparser = subparsers.add_parser(normalize_fn(fn.__name__),
                                          help=fn.__doc__.split("\n")[0],
                                          description=fn.__doc__,
                                          formatter_class=raw)
        if hasattr(fn, "stap_args"):
            for args, kwargs in fn.stap_args:
                subparser.add_argument(*args, **kwargs)

    options = parser.parse_args()
    conditions = [ "(1 == 1)" ]
    if "condition" in options and options.condition:
        conditions.append("({})".format(options.condition))
    if "pid" in options and options.pid:
        conditions.append("(pid() == target())")
    if "process" in options and options.process:
        p = os.path.basename(options.process)
        conditions.append('(execname() == "{}")'.format(p))
    options.condition = "({})".format(" && ".join(conditions))
    return options


def run(module):
    """Process options and execute subcommand"""
    global logger
    options = get_options(module)
    logger = log.get_logger("stap", options)
    try:
        for fn in get_subcommands(module):
            if normalize_fn(fn.__name__) != options.command:
                continue
            logger.debug("execute %s subcommand" % options.command)
            fn(options)
    except Exception as e:
        logger.exception(e)
        sys.exit(1)


def sofiles(pid):
    """Retrieve libraries loaded by the process specified by the PID"""
    args = []
    with open("/proc/{}/maps".format(pid)) as f:
        for line in f:
            mo = re.match(r".*\s+(/\S+\.so)$", line.strip())
            if mo and mo.group(1) not in args:
                logger.debug("{} is using {}".format(pid, mo.group(1)))
                args.append("-d")
                args.append(mo.group(1))
    return args


def execute(probe, options, *args):
    """Execute the given probe with :command:`stap`."""
    cmd = ["stap"]
    if not options.silent:
        cmd += ["-v"]
    if options.stapnooverload:
        cmd += ["-DSTP_NO_OVERLOAD"]
    if options.stapargs:
        cmd += options.stapargs
    if "pid" in options and options.pid:
        cmd += ["-x", str(options.pid)]
        cmd += sofiles(options.pid)
    if "process" in options and options.process:
        if  "/" in options.process:
            cmd += ["-d", options.process, "--ldd"]
        else:
            logger.warn("process is not fully qualified, "
                        "additional symbols may be missing")
    cmd += args
    cmd += ["-"]

    if options.dump:
        logger.info("would run the following probe with `{}`".format(
            " ".join(cmd)))
        print probe
        return

    logger.info("execute probe")
    logger.debug("using the following command line: %s" % " ".join(cmd))
    st = subprocess.Popen(cmd,
                          stdin=subprocess.PIPE)
    try:
        st.communicate(input=probe)
    except KeyboardInterrupt:
        st.terminate()
        st.wait()
        sys.exit(0)

