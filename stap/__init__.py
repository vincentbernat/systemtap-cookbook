import sys
import argparse
import inspect
import subprocess

from stap import log
from stap import d
from stap import h

__all__ = [ "run", "execute", "d", "h" ]

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
    parser.add_argument("--dump", "-D", action="store_true",
                        help="dump the systemtap script source")

    subparsers = parser.add_subparsers(help="subcommands", dest="command")
    for fn in get_subcommands(module):
        subparser = subparsers.add_parser(fn.__name__,
                                          help=fn.__doc__.split("\n")[0],
                                          description=fn.__doc__,
                                          formatter_class=raw)
        if hasattr(fn, "stap_args"):
            for args, kwargs in fn.stap_args:
                subparser.add_argument(*args, **kwargs)

    return parser.parse_args()


def run(module):
    """Process options and execute subcommand"""
    global logger
    options = get_options(module)
    logger = log.get_logger("stap", options)
    try:
        for fn in get_subcommands(module):
            if fn.__name__ != options.command:
                continue
            logger.debug("execute %s subcommand" % options.command)
            fn(options)
    except Exception as e:
        logger.exception(e)
        sys.exit(1)


def execute(probe, options):
    if options.dump:
        logger.debug("dump probe")
        print probe
        return
    cmd = ["stap"]
    if options.stapargs:
        cmd += options.stapargs
    cmd += ["-"]
    logger.info("execute probe")
    logger.debug("using the following command line: %s" % " ".join(cmd))
    st = subprocess.Popen(cmd,
                          stdin=subprocess.PIPE)
    try:
        st.communicate(input=probe)
    except KeyboardInterrupt:
        st.terminate()
        sys.exit(0)

