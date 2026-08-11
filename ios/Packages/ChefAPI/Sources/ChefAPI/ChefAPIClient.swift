import Foundation

public enum ChefAPIError: Error, Equatable, Sendable {
    case invalidBaseURL
    case invalidResponse
    case transport(String)
    case unauthorized
    case twoFactorRequired(String)
    case http(status: Int, message: String, errors: [String: [String]])
    case decoding(String)
}

extension ChefAPIError: LocalizedError {
    public var errorDescription: String? {
        switch self {
        case .invalidBaseURL:
            "Enter a valid HTTPS Chef address. Localhost may use HTTP during development."
        case .invalidResponse:
            "Chef returned an invalid response."
        case let .transport(message):
            message
        case .unauthorized:
            "Your Chef session has expired. Sign in again."
        case let .twoFactorRequired(message):
            message
        case let .http(_, message, errors):
            errors.values.first?.first ?? message
        case let .decoding(message):
            "Chef returned data this app could not read: \(message)"
        }
    }
}

public struct ChefAPIClient: Sendable {
    public let baseURL: URL
    private let session: URLSession
    private let encoder: JSONEncoder
    private let decoder: JSONDecoder

    public init(baseURL: URL, session: URLSession = .shared) {
        self.baseURL = baseURL
        self.session = session
        encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
        decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
    }

    public static func validatedBaseURL(_ rawValue: String) throws -> URL {
        guard
            let url = URL(string: rawValue.trimmingCharacters(in: .whitespacesAndNewlines)),
            let scheme = url.scheme?.lowercased(),
            let host = url.host?.lowercased(),
            !host.isEmpty,
            scheme == "https" || (scheme == "http" && ["localhost", "127.0.0.1", "::1"].contains(host))
        else {
            throw ChefAPIError.invalidBaseURL
        }

        return url
    }

    public func authenticate(_ authentication: AuthenticationRequest) async throws -> TokenResponse {
        try await send(
            path: "auth/tokens",
            method: "POST",
            body: try encode(authentication)
        )
    }

    public func loadSession(token: String) async throws -> SessionResponse {
        try await send(path: "session", token: token)
    }

    public func revokeCurrentToken(token: String) async throws -> TokenRevocationResponse {
        try await send(path: "auth/tokens/current", method: "DELETE", token: token)
    }

    public func loadWorkspace(planID: Int, token: String) async throws -> WorkspaceResponse {
        try await send(path: "meal-plans/\(planID)/workspace", token: token)
    }

    public func approvePlan(planID: Int, token: String) async throws -> WorkspaceResponse {
        try await send(path: "meal-plans/\(planID)/approve", method: "POST", token: token)
    }

    public func loadRetailerPurchasePolicy(token: String) async throws -> RetailerPurchasePolicyEnvelope {
        try await send(path: "retailer-purchase-policy", token: token)
    }

    public func updateRetailerPurchasePolicy(
        _ policy: RetailerPurchasePolicyRequest,
        token: String
    ) async throws -> RetailerPurchasePolicyEnvelope {
        try await send(
            path: "retailer-purchase-policy",
            method: "PUT",
            token: token,
            body: try encode(policy)
        )
    }

    public func updateMealPlanPurchasePreference(
        planID: Int,
        basketTargetCents: Int?,
        token: String
    ) async throws -> MealPlanPurchasePreferenceResponse {
        try await send(
            path: "meal-plans/\(planID)/purchase-preference",
            method: "PUT",
            token: token,
            body: try encode(MealPlanPurchasePreferenceRequest(basketTargetCents: basketTargetCents))
        )
    }

    public func startRetailerConnection(token: String) async throws -> ConnectionStartResponse {
        try await send(path: "retailer-connections", method: "POST", token: token)
    }

    public func verifyRetailerConnection(
        connectionID: Int,
        disclosureVersion: String,
        token: String
    ) async throws -> ConnectionResponse {
        try await send(
            path: "retailer-connections/\(connectionID)/verify",
            method: "POST",
            token: token,
            body: try encode(StandingConsentRequest(disclosureVersion: disclosureVersion))
        )
    }

    public func relayLiveInput(
        connectionID: Int,
        input: LiveInput,
        token: String
    ) async throws -> LiveInputResponse {
        try await send(
            path: "retailer-connections/\(connectionID)/live-input",
            method: "POST",
            token: token,
            body: try encode(input)
        )
    }

    public func releaseLiveSession(
        connectionID: Int,
        token: String
    ) async throws -> LiveSessionReleaseResponse {
        try await send(
            path: "retailer-connections/\(connectionID)/live-session",
            method: "DELETE",
            token: token
        )
    }

    public func disconnectRetailerConnection(
        connectionID: Int,
        token: String
    ) async throws -> ConnectionResponse {
        try await send(
            path: "retailer-connections/\(connectionID)",
            method: "DELETE",
            token: token
        )
    }

    public func revokeStandingConsent(
        connectionID: Int,
        token: String
    ) async throws -> ConnectionResponse {
        try await send(
            path: "retailer-connections/\(connectionID)/grant",
            method: "DELETE",
            token: token
        )
    }

    public func loadBasket(runID: Int, token: String) async throws -> BasketEnvelope {
        try await send(path: "basket-runs/\(runID)", token: token)
    }

    public func retryBasket(runID: Int, token: String) async throws -> BasketMutationResponse {
        try await send(path: "basket-runs/\(runID)/retry", method: "POST", token: token)
    }

    public func restoreBasket(runID: Int, token: String) async throws -> BasketMutationResponse {
        try await send(path: "basket-runs/\(runID)/restore", method: "POST", token: token)
    }

    public func startBasketReview(runID: Int, token: String) async throws -> ReviewSessionResponse {
        try await send(path: "basket-runs/\(runID)/review-session", method: "POST", token: token)
    }

    public func rememberProductPreference(
        runID: Int,
        itemID: Int,
        candidateID: Int,
        token: String
    ) async throws -> RetailerProductPreferenceResponse {
        try await send(
            path: "basket-runs/\(runID)/items/\(itemID)/product-preference",
            method: "PUT",
            token: token,
            body: try encode(RetailerProductPreferenceRequest(
                retailerProductCandidateId: candidateID
            ))
        )
    }

    public func revokeProductPreference(
        preferenceID: Int,
        token: String
    ) async throws -> RetailerProductPreferenceResponse {
        try await send(
            path: "retailer-product-preferences/\(preferenceID)",
            method: "DELETE",
            token: token
        )
    }

    public func overrideBasketBudget(
        runID: Int,
        token: String
    ) async throws -> BasketBudgetOverrideResponse {
        try await send(
            path: "basket-runs/\(runID)/budget-override",
            method: "POST",
            token: token
        )
    }

    public func markNotificationRead(
        notificationID: String,
        token: String
    ) async throws -> NotificationReadResponse {
        try await send(
            path: "notifications/\(notificationID)/read",
            method: "PUT",
            token: token
        )
    }

    private func encode<Body: Encodable>(_ body: Body) throws -> Data {
        try encoder.encode(body)
    }

    private func send<Response: Decodable>(
        path: String,
        method: String = "GET",
        token: String? = nil,
        body: Data? = nil
    ) async throws -> Response {
        var request = URLRequest(url: endpoint(path))
        request.httpMethod = method
        request.httpBody = body
        request.timeoutInterval = 45
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        if body != nil {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }

        if let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await session.data(for: request)
        } catch {
            throw ChefAPIError.transport(error.localizedDescription)
        }

        guard let httpResponse = response as? HTTPURLResponse else {
            throw ChefAPIError.invalidResponse
        }

        guard (200...299).contains(httpResponse.statusCode) else {
            throw decodeError(data: data, status: httpResponse.statusCode)
        }

        do {
            return try decoder.decode(Response.self, from: data)
        } catch {
            throw ChefAPIError.decoding(String(describing: error))
        }
    }

    private func endpoint(_ path: String) -> URL {
        baseURL
            .appendingPathComponent("api")
            .appendingPathComponent("v1")
            .appendingPathComponent(path)
    }

    private func decodeError(data: Data, status: Int) -> ChefAPIError {
        if status == 401 {
            return .unauthorized
        }

        let payload = try? decoder.decode(ErrorPayload.self, from: data)
        let message = payload?.message ?? "Chef could not complete that request."

        if payload?.twoFactorRequired == true {
            return .twoFactorRequired(message)
        }

        return .http(
            status: status,
            message: message,
            errors: payload?.errors ?? [:]
        )
    }
}

private struct MealPlanPurchasePreferenceRequest: Encodable {
    let basketTargetCents: Int?
}

private struct RetailerProductPreferenceRequest: Encodable {
    let retailerProductCandidateId: Int
}

private struct ErrorPayload: Decodable {
    let message: String?
    let twoFactorRequired: Bool?
    let errors: [String: [String]]?
}
