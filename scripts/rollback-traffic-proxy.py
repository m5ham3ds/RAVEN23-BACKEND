#!/usr/bin/env python3
"""Loopback-only HTTP traffic selector for CI rollback rehearsal."""
import argparse
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


class Handler(BaseHTTPRequestHandler):
    backend_file: Path

    def do_GET(self):
        conn = None
        try:
            port = int(self.backend_file.read_text(encoding="ascii").strip())
            if not 1 <= port <= 65535:
                raise ValueError("invalid backend port")
            conn = http.client.HTTPConnection("127.0.0.1", port, timeout=4)
            conn.request("GET", self.path, headers={"Connection": "close"})
            response = conn.getresponse()
            body = response.read()
            self.send_response(response.status)
            for name, value in response.getheaders():
                if name.lower() not in {"connection", "keep-alive", "transfer-encoding", "content-length"}:
                    self.send_header(name, value)
            self.send_header("X-RAVEN23-Backend-Port", str(port))
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except Exception as exc:
            self.send_error(502, "active backend unavailable: " + str(exc))
        finally:
            if conn is not None:
                conn.close()

    def log_message(self, fmt, *args):
        print("rollback-proxy: " + (fmt % args), flush=True)


parser = argparse.ArgumentParser()
parser.add_argument("--backend-file", required=True, type=Path)
parser.add_argument("--port", required=True, type=int)
args = parser.parse_args()
if not 1 <= args.port <= 65535 or not args.backend_file.is_file():
    raise SystemExit("invalid proxy configuration")
Handler.backend_file = args.backend_file
ThreadingHTTPServer(("127.0.0.1", args.port), Handler).serve_forever()
