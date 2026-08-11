import ChefAPI
import Foundation
import XCTest
@testable import Chef

final class M51NativePresentationTests: XCTestCase {
    override func tearDown() {
        AppStateMockURLProtocol.handler = nil
        super.tearDown()
    }

    func testCurrencyInputAcceptsDollarsAndConvertsToCents() {
        XCTAssertEqual(CurrencyInput("125.50"), .valid(12_550))
        XCTAssertEqual(CurrencyInput(" 0 "), .valid(0))
    }

    func testCurrencyInputAllowsAnUnsetTarget() {
        XCTAssertEqual(CurrencyInput("   "), .empty)
        XCTAssertNil(CurrencyInput("").cents)
    }

    func testCurrencyInputRejectsNegativeAndNonNumericTargets() {
        XCTAssertEqual(CurrencyInput("-1"), .invalid)
        XCTAssertEqual(CurrencyInput("about 80"), .invalid)
    }

    func testNewRecoveryStatusesRemainActionable() {
        let budget = GroceryStatusPresentation.make(status: "needs_plan_review")
        let adjustment = GroceryStatusPresentation.make(status: "preparing_resolution")

        XCTAssertTrue(budget.needsAttention)
        XCTAssertTrue(adjustment.isActive)
        XCTAssertFalse(adjustment.needsAttention)
    }

    @MainActor
    func testFailedSessionBootstrapRevokesAndForgetsTheIssuedToken() async {
        let tokenStore = InMemoryTokenStore()
        let requestRecorder = RequestRecorder()
        AppStateMockURLProtocol.handler = { request in
            requestRecorder.record(request)

            switch (request.httpMethod, request.url?.path) {
            case ("POST", "/api/v1/auth/tokens"):
                return Self.response(
                    request,
                    status: 200,
                    json: """
                    {
                      "token": "issued-token",
                      "expires_at": "2026-09-01T10:00:00+10:00",
                      "user": {"id": 1, "name": "Dan", "email": "dan@example.test"}
                    }
                    """
                )
            case ("GET", "/api/v1/session"):
                return Self.response(request, status: 503, json: "{\"message\":\"Session unavailable\"}")
            case ("DELETE", "/api/v1/auth/tokens/current"):
                return Self.response(request, status: 200, json: "{\"revoked\":true}")
            default:
                throw URLError(.badURL)
            }
        }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [AppStateMockURLProtocol.self]
        let client = ChefAPIClient(
            baseURL: URL(string: "https://chef.example.test/")!,
            session: URLSession(configuration: configuration)
        )
        let appState = AppState(
            tokenStore: tokenStore,
            clientFactory: { _ in client }
        )

        await appState.signIn(
            email: "dan@example.test",
            password: "password",
            twoFactorCode: nil
        )

        XCTAssertFalse(appState.isAuthenticated)
        XCTAssertNil(tokenStore.token)
        XCTAssertEqual(tokenStore.deleteCount, 1)
        XCTAssertEqual(
            requestRecorder.paths,
            [
                "POST /api/v1/auth/tokens",
                "GET /api/v1/session",
                "DELETE /api/v1/auth/tokens/current",
            ]
        )
    }

    private static func response(
        _ request: URLRequest,
        status: Int,
        json: String
    ) -> (HTTPURLResponse, Data) {
        (
            HTTPURLResponse(
                url: request.url!,
                statusCode: status,
                httpVersion: nil,
                headerFields: ["Content-Type": "application/json"]
            )!,
            Data(json.utf8)
        )
    }
}

private final class InMemoryTokenStore: TokenStoring, @unchecked Sendable {
    private(set) var token: String?
    private(set) var deleteCount = 0

    func load() -> String? {
        token
    }

    func save(_ token: String) throws {
        self.token = token
    }

    func delete() {
        token = nil
        deleteCount += 1
    }
}

private final class RequestRecorder: @unchecked Sendable {
    private let lock = NSLock()
    private var recordedPaths: [String] = []

    var paths: [String] {
        lock.withLock { recordedPaths }
    }

    func record(_ request: URLRequest) {
        lock.withLock {
            recordedPaths.append("\(request.httpMethod ?? "") \(request.url?.path ?? "")")
        }
    }
}

private final class AppStateMockURLProtocol: URLProtocol, @unchecked Sendable {
    nonisolated(unsafe) static var handler: (@Sendable (URLRequest) throws -> (HTTPURLResponse, Data))?

    override class func canInit(with request: URLRequest) -> Bool {
        true
    }

    override class func canonicalRequest(for request: URLRequest) -> URLRequest {
        request
    }

    override func startLoading() {
        guard let handler = Self.handler else {
            XCTFail("AppStateMockURLProtocol handler was not configured.")
            return
        }

        do {
            let (response, data) = try handler(request)
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}
