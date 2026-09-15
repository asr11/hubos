#!/bin/bash
for file in src/lib/financeAgentStore.ts src/lib/hubApiHttpUtil.ts src/lib/hubApiRegistry.ts src/lib/morningCredentials.ts src/lib/morningExpenseSync.ts src/lib/morningSync.ts src/scripts/auditExpenseSources.ts src/scripts/removeJulyGiExpenses.ts
do
  # Replace getAdminDb import with a mock
  sed -i "s|import { getAdminDb } from '\./firebaseAdmin\.js'|const getAdminDb = (): any => ({} as any)|g" "$file"
  sed -i "s|import { getAdminDb } from '\.\./lib/firebaseAdmin\.js'|const getAdminDb = (): any => ({} as any)|g" "$file"
  
  # Replace firestore imports
  sed -i "s|import { FieldValue } from 'firebase-admin/firestore'|const FieldValue = { serverTimestamp: () => new Date(), delete: () => null } as any|g" "$file"
  sed -i "s|import { FieldValue, type Firestore } from 'firebase-admin/firestore'|const FieldValue = { serverTimestamp: () => new Date(), delete: () => null } as any; type Firestore = any|g" "$file"
  sed -i "s|import type { Firestore, QueryDocumentSnapshot } from 'firebase-admin/firestore'|type Firestore = any; type QueryDocumentSnapshot = any|g" "$file"
  sed -i "s|import { Timestamp } from 'firebase-admin/firestore'|const Timestamp = { fromDate: (d: Date) => d } as any|g" "$file"

  # Fix namespaces
  sed -i "s|FirebaseFirestore\.Query|any|g" "$file"
  sed -i "s|FirebaseFirestore\.DocumentData|any|g" "$file"
done
