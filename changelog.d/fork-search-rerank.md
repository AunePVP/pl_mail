**A search can show its best matches first.** With a reranking model configured
(`FORK_RERANK_URL`, a `/rerank` endpoint such as Qwen3-Reranker), the first fifty results are scored
for how well they answer the search, and the up to three clearly best are shown above the list
about a second later, recent mail first among equals. The AI answer then reads the best twenty
rather than the newest twenty. Without the variable nothing changes. No schema change.
