"""A tiny TAP-style counter: ``ok N - …`` / ``not ok N - …`` and a final ``N checks green`` (the estate's proof style)."""
import os
import sys
import traceback

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "services"))


class Tap:
    def __init__(self, name: str):
        self.name, self.n, self.bad = name, 0, 0

    def ok(self, cond, desc: str, detail=None):
        self.n += 1
        if cond:
            print(f"ok {self.n} - {desc}", flush=True)
        else:
            self.bad += 1
            print(f"not ok {self.n} - {desc}" + (f"  # {detail}" if detail is not None else ""), flush=True)
        return bool(cond)

    def raises(self, fn, exc, desc: str, check=None):
        try:
            fn()
        except exc as e:
            return self.ok(check(e) if check else True, desc, None if not check else str(e)[:200])
        except Exception as e:  # noqa
            return self.ok(False, desc, f"raised {type(e).__name__}: {str(e)[:200]}")
        return self.ok(False, desc, "did not raise")

    def crash(self, where: str):
        self.n += 1
        self.bad += 1
        print(f"not ok {self.n} - {where} crashed", flush=True)
        traceback.print_exc()

    def done(self) -> int:
        green = self.n - self.bad
        print(f"# {self.name}: {green} of {self.n} checks green", flush=True)
        return 0 if self.bad == 0 else 1
