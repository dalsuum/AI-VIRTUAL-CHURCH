# Bible Knowledge Model — Invariants

Architectural invariants for the platform's Bible knowledge model (the canonical
ontology in `workers/data/books_meta.json` and the locale resources that render
it). These are **project rules, not implementation details**. Every future PR —
human- or AI-authored — must preserve them unless there is a compelling,
documented reason to change them.

## 1. Stable canonical IDs
Every Bible entity has a permanent canonical identifier (`genesis`, `isaiah`,
`matthew`, `revelation`). **Canonical IDs never change.** Localized names,
aliases, and translations may change; IDs do not.

## 2. IDs are internal
Internal logic and relationships reference IDs only — never localized strings,
display names, or abbreviations. (`related_books: ["isaiah","matthew"]`, never
`["Isaiah","Ésaïe"]`.)

## 3. Display is localization
Everything shown to a user derives from **canonical ID + locale resources**.
Display text is never the source of truth.

## 4. Metadata is additive
Metadata may expand. Existing fields must not change semantics. Consumers ignore
fields they don't understand.

## 5. Search is independent
Search indexes are implementation details, generated *from* the knowledge model.
The model is not optimized for search; never redesign metadata solely for search
performance.

## 6. AI uses knowledge
AI consumes canonical metadata; it must not duplicate theological/structural
facts inside prompt templates. **Prompt templates describe behavior; the
knowledge model supplies facts.**

## 7. Per-consumer ownership
Each runtime owns the datasets it consumes — frontend: UI translations; backend:
API localization + validation; workers: AI prompts, Bible ontology, search
metadata. No dataset duplicated across runtimes; the join key is the locale code.

## 8. Localization is data
Adding a language should be primarily adding locale resources — rarely
application code changes.

## 9. Backward compatibility
Knowledge-model additions are additive. Existing APIs keep working; existing
search behavior is unchanged unless intentionally improved.

## 10. Doctrine neutrality
The model holds canonical *structural* information only. Doctrinal interpretation
lives in AI personas, study content, or other higher-level resources — not the
ontology.

## 11. Single source of truth
Each fact has one authoritative owner. No mirrored copies, no synchronization
steps, no generated duplicates unless they are build artifacts.

## 12. Knowledge over plumbing
Future releases prioritize richer biblical knowledge and language quality over
new framework infrastructure. The multilingual infrastructure is considered
complete unless a concrete feature requires new capability.
