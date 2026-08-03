/**
 * Structural invariants for src/index.js.
 *
 * The Miniflare suite pins the response paths that exist TODAY. These pin the
 * SHAPE of paths added tomorrow — the JS analogue of the PHP side's
 * OutboundHttpGuardTest.
 *
 * Parses with the TypeScript compiler API rather than a regex: `new Response`
 * inside a comment or a template literal must not count, and only a real parse
 * knows the difference. typescript is already a devDependency (tsconfig.json),
 * so this adds nothing to the tree.
 */
import {readFileSync} from "node:fs";
import {fileURLToPath} from "node:url";
import ts from "typescript";
import {describe, expect, it} from "vitest";

const srcPath = fileURLToPath(new URL("../src/index.js", import.meta.url));
const source = readFileSync(srcPath, "utf8");
// setParentNodes: true — enclosingFunctionName() and isInsideCallTo() walk up.
const sourceFile = ts.createSourceFile(
    srcPath,
    source,
    ts.ScriptTarget.ES2022,
    true,
    ts.ScriptKind.JS,
);

function walk(node, visit) {
    visit(node);
    ts.forEachChild(node, (child) => walk(child, visit));
}

/** 1-indexed line number, for failure messages a human can act on. */
function lineOf(node) {
    return sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1;
}

/** Name of the nearest enclosing function or method declaration. */
function enclosingFunctionName(node) {
    for (let n = node.parent; n; n = n.parent) {
        if (ts.isFunctionDeclaration(n) && n.name) return n.name.text;
        if (ts.isMethodDeclaration(n) && ts.isIdentifier(n.name)) return n.name.text;
    }
    return "<module>";
}

/** True when `node` sits anywhere inside a call to the named free function. */
function isInsideCallTo(node, fnName) {
    for (let n = node.parent; n; n = n.parent) {
        if (
            ts.isCallExpression(n) &&
            ts.isIdentifier(n.expression) &&
            n.expression.text === fnName
        ) {
            return true;
        }
    }
    return false;
}

describe("INV-1: every ctx.waitUntil() argument ends in .catch()", () => {
    // A rejected promise handed to waitUntil after the response has returned
    // becomes an unhandled rejection instead of a structured log line (EDGE-13).
    // @typescript-eslint/no-floating-promises does NOT catch this: the promise
    // is an argument, not an expression statement.
    it("holds", () => {
        const offenders = [];
        walk(sourceFile, (node) => {
            if (!ts.isCallExpression(node)) return;
            const callee = node.expression;
            if (!ts.isPropertyAccessExpression(callee) || callee.name.text !== "waitUntil") {
                return;
            }

            // Fails closed: the ONLY accepted shape is a call chain whose
            // outermost member call is `.catch`. A bare identifier, a
            // conditional, or anything else this cannot follow is an offender —
            // an indirection the checker can't see is exactly where a missing
            // .catch() would hide.
            const arg = node.arguments[0];
            const ok =
                arg !== undefined &&
                ts.isCallExpression(arg) &&
                ts.isPropertyAccessExpression(arg.expression) &&
                arg.expression.name.text === "catch";

            if (!ok) offenders.push(`line ${lineOf(node)}`);
        });
        expect(offenders).toEqual([]);
    });
});

describe("INV-2: no visitor-facing `new Response` outside finalize()", () => {
    // Every return path must carry the baseline security headers. finalize() is
    // where they are applied; a `new Response` that never reaches it ships bare.
    //
    // Two exemptions, both constructing a Response for a non-visitor purpose:
    //   finalize()     — the wrapper itself
    //   withCacheTtl() — builds the cache copy, never returned to a visitor
    //
    // passThrough()'s raw 101 return is NOT an exemption: it returns an existing
    // Response rather than constructing one, so this rule never sees it. Do not
    // "fix" it by wrapping — that drops response.webSocket and breaks the
    // connection.
    const EXEMPT = new Set(["finalize", "withCacheTtl"]);

    it("holds", () => {
        const offenders = [];
        walk(sourceFile, (node) => {
            if (!ts.isNewExpression(node)) return;
            if (!ts.isIdentifier(node.expression) || node.expression.text !== "Response") return;
            if (isInsideCallTo(node, "finalize")) return;

            const fn = enclosingFunctionName(node);
            if (EXEMPT.has(fn)) return;

            offenders.push(`line ${lineOf(node)} in ${fn}()`);
        });
        expect(offenders).toEqual([]);
    });
});

describe("INV-3: the RESERVED set has no duplicate entries", () => {
    // A Set silently absorbs duplicates, so a copy-paste slip in a
    // hand-maintained 298-entry literal is invisible both on inspection and in
    // behaviour. Only a test can see it.
    it("holds", () => {
        let literals = null;
        walk(sourceFile, (node) => {
            if (!ts.isVariableDeclaration(node)) return;
            if (!ts.isIdentifier(node.name) || node.name.text !== "RESERVED") return;
            const init = node.initializer;
            if (!init || !ts.isNewExpression(init)) return;
            const arg = init.arguments?.[0];
            if (!arg || !ts.isArrayLiteralExpression(arg)) return;
            literals = arg.elements.filter((el) => ts.isStringLiteral(el)).map((el) => el.text);
        });

        // Guards against the test silently passing if RESERVED is restructured.
        expect(
            literals,
            "RESERVED array literal not found — has it been refactored?",
        ).not.toBeNull();
        expect(literals.length).toBeGreaterThan(200);

        const seen = new Set();
        const dupes = [];
        for (const value of literals) {
            if (seen.has(value)) dupes.push(value);
            seen.add(value);
        }
        expect(dupes).toEqual([]);
    });
});

describe("INV-4: no bare fetch() outside passThrough()", () => {
    // Every outbound hop should be either the PARTNA_PAGES service binding or
    // the single deliberate origin pass-through. A bare fetch() anywhere else is
    // an unreviewed egress path from the edge.
    it("holds", () => {
        const offenders = [];
        walk(sourceFile, (node) => {
            if (!ts.isCallExpression(node)) return;
            // Bare `fetch(...)` only — `env.PARTNA_PAGES.fetch(...)` is a
            // PropertyAccessExpression and is not matched here.
            if (!ts.isIdentifier(node.expression) || node.expression.text !== "fetch") return;

            const fn = enclosingFunctionName(node);
            if (fn === "passThrough") return;

            offenders.push(`line ${lineOf(node)} in ${fn}()`);
        });
        expect(offenders).toEqual([]);
    });
});
