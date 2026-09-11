<?php

namespace App\Wealthsimple;

/** GraphQL operations copied from the Bagholder master client (read-only). */
final class Queries
{
    public const Q_FETCH_ALL_ACCOUNT_FINANCIALS = <<<'GQL'
query FetchAllAccountFinancials($identityId: ID!, $startDate: Date, $pageSize: Int = 25, $cursor: String) {
  identity(id: $identityId) {
    id
    ...AllAccountFinancials
    __typename
  }
}

fragment AllAccountFinancials on Identity {
  accounts(filter: {}, first: $pageSize, after: $cursor) {
    pageInfo {
      hasNextPage
      endCursor
      __typename
    }
    edges {
      cursor
      node {
        ...AccountWithFinancials
        __typename
      }
      __typename
    }
    __typename
  }
  __typename
}

fragment AccountWithFinancials on Account {
  ...AccountWithLink
  ...AccountFinancials
  __typename
}

fragment AccountWithLink on Account {
  ...Account
  linkedAccount {
    ...Account
    __typename
  }
  __typename
}

fragment Account on Account {
  ...AccountCore
  custodianAccounts {
    ...CustodianAccount
    __typename
  }
  __typename
}

fragment AccountCore on Account {
  id
  archivedAt
  branch
  closedAt
  createdAt
  cacheExpiredAt
  currency
  requiredIdentityVerification
  unifiedAccountType
  supportedCurrencies
  nickname
  status
  accountOwnerConfiguration
  accountFeatures {
    ...AccountFeature
    __typename
  }
  accountOwners {
    ...AccountOwner
    __typename
  }
  type
  __typename
}

fragment AccountFeature on AccountFeature {
  name
  enabled
  __typename
}

fragment AccountOwner on AccountOwner {
  accountId
  identityId
  accountNickname
  clientCanonicalId
  accountOpeningAgreementsSigned
  name
  email
  ownershipType
  activeInvitation {
    ...AccountOwnerInvitation
    __typename
  }
  sentInvitations {
    ...AccountOwnerInvitation
    __typename
  }
  __typename
}

fragment AccountOwnerInvitation on AccountOwnerInvitation {
  id
  createdAt
  inviteeName
  inviteeEmail
  inviterName
  inviterEmail
  updatedAt
  sentAt
  status
  __typename
}

fragment CustodianAccount on CustodianAccount {
  id
  branch
  custodian
  status
  updatedAt
  __typename
}

fragment AccountFinancials on Account {
  id
  custodianAccounts {
    id
    branch
    financials {
      current {
        ...CustodianAccountCurrentFinancialValues
        __typename
      }
      __typename
    }
    __typename
  }
  financials {
    currentCombined {
      id
      ...AccountCurrentFinancials
      __typename
    }
    __typename
  }
  __typename
}

fragment CustodianAccountCurrentFinancialValues on CustodianAccountCurrentFinancialValues {
  deposits { ...Money __typename }
  earnings { ...Money __typename }
  netDeposits { ...Money __typename }
  netLiquidationValue { ...Money __typename }
  withdrawals { ...Money __typename }
  __typename
}

fragment Money on Money {
  amount
  cents
  currency
  __typename
}

fragment AccountCurrentFinancials on AccountCurrentFinancials {
  id
  netLiquidationValue { ...Money __typename }
  netDeposits { ...Money __typename }
  simpleReturns(referenceDate: $startDate) { ...SimpleReturns __typename }
  totalDeposits { ...Money __typename }
  totalWithdrawals { ...Money __typename }
  __typename
}

fragment SimpleReturns on SimpleReturns {
  amount { ...Money __typename }
  asOf
  rate
  referenceDate
  __typename
}
GQL;

    public const Q_FETCH_ACTIVITY_FEED_ITEMS = <<<'GQL'
query FetchActivityFeedItems($first: Int, $cursor: Cursor, $condition: ActivityCondition, $orderBy: [ActivitiesOrderBy!] = OCCURRED_AT_DESC) {
  activityFeedItems(
    first: $first
    after: $cursor
    condition: $condition
    orderBy: $orderBy
  ) {
    edges {
      node {
        ...Activity
        __typename
      }
      __typename
    }
    pageInfo {
      hasNextPage
      endCursor
      __typename
    }
    __typename
  }
}

fragment Activity on ActivityFeedItem {
  accountId
  aftOriginatorName
  aftTransactionCategory
  aftTransactionType
  amount
  amountSign
  assetQuantity
  assetSymbol
  canonicalId
  currency
  eTransferEmail
  eTransferName
  externalCanonicalId
  identityId
  institutionName
  occurredAt
  p2pHandle
  p2pMessage
  spendMerchant
  securityId
  billPayCompanyName
  billPayPayeeNickname
  redactedExternalAccountNumber
  opposingAccountId
  status
  subType
  type
  strikePrice
  contractType
  expiryDate
  chequeNumber
  provisionalCreditAmount
  primaryBlocker
  interestRate
  frequency
  counterAssetSymbol
  rewardProgram
  counterPartyCurrency
  counterPartyCurrencyAmount
  counterPartyName
  fxRate
  fees
  reference
  __typename
}
GQL;

    public const Q_FETCH_ACCOUNTS_WITH_BALANCE = <<<'GQL'
query FetchAccountsWithBalance($ids: [String!]!, $type: BalanceType!) {
  accounts(ids: $ids) {
    ...AccountWithBalance
    __typename
  }
}

fragment AccountWithBalance on Account {
  id
  custodianAccounts {
    id
    financials {
      ... on CustodianAccountFinancialsSo {
        balance(type: $type) {
          ...Balance
          __typename
        }
        __typename
      }
      __typename
    }
    __typename
  }
  __typename
}

fragment Balance on Balance {
  quantity
  securityId
  __typename
}
GQL;

    public const Q_FETCH_SECURITY_SEARCH_RESULT = <<<'GQL'
query FetchSecuritySearchResult($query: String!) {
  securitySearch(input: {query: $query}) {
    results {
      ...SecuritySearchResult
      __typename
    }
    __typename
  }
}

fragment SecuritySearchResult on Security {
  id
  buyable
  status
  stock {
    symbol
    name
    primaryExchange
    __typename
  }
  securityGroups {
    id
    name
    __typename
  }
  quoteV2 {
    ... on EquityQuote {
      marketStatus
      __typename
    }
    __typename
  }
  __typename
}
GQL;

    public const Q_IDENTITY_HISTORICAL_FINANCIALS = <<<'GQL'
query IdentityHistoricalFinancialsQuery(
  $identityId: ID!
  $currency: Currency!
  $startDate: Date!
  $endDate: Date
  $limit: Int
  $cursor: String
  $includeNetDeposits: Boolean = true
) {
  identity(id: $identityId) {
    id
    financials(filter: { archived: false }) {
      historicalDaily(
        currency: $currency
        startDate: $startDate
        endDate: $endDate
        first: $limit
        after: $cursor
      ) {
        edges {
          cursor
          node {
            date
            netLiquidationValue { amount currency __typename }
            netDeposits @include(if: $includeNetDeposits) { amount currency __typename }
            __typename
          }
          __typename
        }
        pageInfo {
          endCursor
          hasNextPage
          __typename
        }
        __typename
      }
      __typename
    }
    __typename
  }
}
GQL;

    public const Q_FETCH_ACCOUNT_HISTORICAL_FINANCIALS = <<<'GQL'
query FetchAccountHistoricalFinancials(
  $id: ID!
  $currency: Currency!
  $startDate: Date
  $resolution: DateResolution!
  $endDate: Date
  $first: Int
  $cursor: String
) {
  account(id: $id) {
    id
    financials {
      historicalDaily(
        currency: $currency
        startDate: $startDate
        resolution: $resolution
        endDate: $endDate
        first: $first
        after: $cursor
      ) {
        edges {
          node {
            date
            netLiquidationValueV2 { amount currency __typename }
            netDepositsV2 { amount currency __typename }
            __typename
          }
          __typename
        }
        pageInfo {
          hasNextPage
          endCursor
          __typename
        }
        __typename
      }
      __typename
    }
    __typename
  }
}
GQL;

    public const Q_FETCH_SECURITY = <<<'GQL'
query FetchSecurity($securityId: ID!) {
  security(id: $securityId) {
    id
    currency
    stock { name primaryExchange primaryMic symbol }
    optionDetails { underlyingSecurity { id currency } }
    __typename
  }
}
GQL;


    public const Q_FETCH_SECURITIES_SUMMARY = <<<'GQL'
query FetchSecuritiesSummary($ids: [ID!]!) {
  securities(ids: $ids) {
    id
    buyable
    sellable
    wsTradeEligible
    securityType
    currency
    status
    stock { name symbol primaryExchange primaryMic }
    optionDetails { multiplier optionType strikePrice expiryDate underlyingSecurity { id } }
    quoteV2(currency: null) {
      __typename
      securityId
      ask
      bid
      currency
      price
      sessionPrice
      quotedAsOf
      previousBaseline
      ... on EquityQuote { marketStatus askSize bidSize close high last lastSize low open mid referenceClose }
      ... on OptionQuote { marketStatus askSize bidSize close high last lastSize low open mid underlyingSpot }
    }
  }
}
GQL;

    public const Q_FETCH_SECURITY_MARKET_DATA = <<<'GQL'
query FetchSecurityMarketData($id: ID!) {
  security(id: $id) {
    id
    allowedOrderSubtypes
    marginRates { clientMarginRate }
  }
}
GQL;

    public const Q_FETCH_TRADING_BALANCE_BUYING_POWER = <<<'GQL'
query FetchTradingBalanceBuyingPower($accountCanonicalId: ID!, $currency: Currency!, $securityId: ID) {
  account(id: $accountCanonicalId) {
    id
    financials {
      current {
        id
        tradingBalanceViewV2 {
          id
          buyingPower(securityId: $securityId, currency: $currency) { id quantity currency }
          cash(currency: $currency) { id quantity currency }
        }
      }
    }
  }
}
GQL;

    public const Q_SO_ORDERS_ORDER_CANCEL = <<<'GQL'
mutation SoOrdersOrderCancel($cancelOrderRequest: CancelOrderRequest!) {
  orderServiceCancelOrder(cancelOrderRequest: $cancelOrderRequest) {
    externalId
    errors { code message }
  }
}
GQL;

    public const Q_SO_ORDERS_ORDER_MODIFY = <<<'GQL'
mutation SoOrdersOrderModify($input: SoOrders_ModifyOrderInput!) {
  soOrdersModifyOrder(input: $input) {
    errors { code message }
  }
}
GQL;

    public const Q_SO_ORDERS_ORDER_CREATE = <<<'GQL'
mutation SoOrdersOrderCreate($input: SoOrders_CreateOrderInput!) {
  soOrdersCreateOrder(input: $input) {
    errors { code message }
    order { orderId createdAt }
  }
}
GQL;

    public const Q_FETCH_SO_ORDERS_EXTENDED_ORDER = <<<'GQL'
query FetchSoOrdersExtendedOrder($branchId: String!, $externalId: String!) {
  soOrdersExtendedOrder(branchId: $branchId, externalId: $externalId) {
    averageFilledPrice
    filledQuantity
    firstFilledAtUtc
    lastFilledAtUtc
    limitPrice
    orderType
    rejectionCause
    rejectionCode
    securityCurrency
    status
    stopPrice
    submittedAtUtc
    submittedQuantity
    timeInForce
    accountId
    canonicalAccountId
    cancellationCutoff
    expiredAtUtc
    securityId
  }
}
GQL;

    public const Q_ORDER_SERVICE_EXTENDED_ORDER_FEED = <<<'GQL'
query OrderServiceExtendedOrderFeed($identityId: ID!, $statuses: [OrderServiceOrderStatus!]!, $first: Int = 25, $cursor: String) {
  identity(id: $identityId) {
    id
    orderServiceExtendedOrderFeed(statuses: $statuses, first: $first, after: $cursor) {
      edges {
        cursor
        node {
          id
          orderId
          canonicalAccountId
          createdAtUtc
          status
          side
          executionType
          submittedQuantity
          limitPrice
          stopPrice
          averageFillPrice
          securityCurrency
          securityId
          symbol
          security { id stock { symbol name } }
        }
      }
      pageInfo { endCursor hasNextPage }
    }
  }
}
GQL;

}
