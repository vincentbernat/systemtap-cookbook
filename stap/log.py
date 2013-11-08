"""Logging related stuff"""

import logging

class ColorizingStreamHandler(logging.StreamHandler):
    """Provide a nicer logging output to error output with colors."""
    colors = 'black red green yellow blue magenta cyan white'.split(" ")
    color_map = dict([(x, colors.index(x)) for x in colors])
    level_map = {
        logging.DEBUG:    (None,  'blue',   " DBG"),
        logging.INFO:     (None,  'green',  "INFO"),
        logging.WARNING:  (None,  'yellow', "WARN"),
        logging.ERROR:    (None,  'red',    " ERR"),
        logging.CRITICAL: ('red', 'white',  "CRIT")
        }
    csi = '\x1b['
    reset = '\x1b[0m'

    @property
    def is_tty(self):
        isatty = getattr(self.stream, 'isatty', None)
        return isatty and isatty()

    def format(self, record):
        message = logging.StreamHandler.format(self, record)
        # Build the prefix
        params = []
        levelno = record.levelno
        if levelno not in self.level_map:
            levelno = logging.WARNING
        bg, fg, level = self.level_map[levelno]
        if bg in self.color_map:
            params.append(str(self.color_map[bg] + 40))
        if fg in self.color_map:
            params.append(str(self.color_map[fg] + 30))
        params.append("1m")
        level = "[{}]".format(level)

        return "\n".join(["{}: {}".format(
            self.is_tty and params and ''.join((self.csi, ';'.join(params),
                                                level, self.reset)) or level,
            line) for line in message.split('\n')])

def get_logger(name, options):
    """Get a colorized stream logger"""
    logger = logging.getLogger(name)
    logger.addHandler(ColorizingStreamHandler())
    logger.setLevel(options.debug and logging.DEBUG or
                    options.silent and logging.WARNING or logging.INFO)
    return logger
