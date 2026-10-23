# AI Usage Disclosure

**Candidate:** Jagadeesh Chinta (`chintajagadeesh905@gmail.com`)  
**Assessment:** TRIPPERS SHIELD (TC.43933.2027.64564 & TC.43933.2027.64696)  
**Reviewer:** @MasterAdmin2  

---

## Tools Used

1. **Antigravity IDE (Claude)** — AI coding assistant used for code generation, debugging, and documentation
2. **GitHub Copilot** — Inline code suggestions during development

## How AI Helped

1. **Boilerplate generation** — AI generated the initial Laravel project structure, Docker configuration, and migration files, saving significant setup time.

2. **Premium calculation logic** — AI helped implement the `bcmath`-based premium calculator with all multiplier lookups. I verified the output against the assignment's example (PLUS plan, 5 days, INTERNATIONAL, ₹300K → ₹225.00) and additional manual calculations.

3. **Idempotency patterns** — AI suggested the dual-layer approach for duplicate prevention (application-level check + database unique constraint + exception handling for race conditions), which I validated by writing tests that simulate concurrent requests.

## Incorrect/Incomplete Suggestions

1. **State machine transitions** — AI initially suggested allowing `ISSUED → FAILED` transitions, which would violate the requirement that ISSUED is a terminal state. I corrected this by removing FAILED from the allowed transitions for ISSUED state.

2. **Premium rounding** — The initial suggestion used PHP's native `round()` function, which is not decimal-safe for financial calculations. I replaced it with a bcmath-based rounding implementation to ensure precision.

## Verification Steps

1. **Unit tests** — Wrote comprehensive unit tests for the PremiumCalculator with known expected values from the assignment spec
2. **Feature tests** — Created end-to-end API tests covering all acceptance criteria: valid requests, validation errors, duplicate intake, duplicate callbacks, state protection
3. **Manual testing** — Used curl commands to test all API endpoints and verify response formats
4. **Code review** — Manually reviewed all generated code for correctness, security (no PII logging), and adherence to Laravel best practices
